<?php

declare(strict_types=1);

namespace App\Controller;

use App\Activite\ProgressionActiviteCalculator;
use App\Budget\GrandeursDerivees;
use App\Entity\Activite;
use App\Entity\EtapeActivite;
use App\Entity\Exercice;
use App\Entity\Indicateur;
use App\Entity\JournalActivite;
use App\Entity\PieceJointe;
use App\Entity\Projet;
use App\Entity\SousActivite;
use App\Entity\Utilisateur;
use App\Enum\FamilleDocument;
use App\Enum\TypeDocument;
use App\Export\LibellesExport;
use App\Export\TableurExport;
use App\Query\ActiviteRecenteReader;
use App\Query\AlertesReader;
use App\Query\ArbreReader;
use App\Query\ExecutionSyntheseReader;
use App\Query\SyntheseReader;
use App\Repository\ActiviteRepository;
use App\Repository\ComposanteRepository;
use App\Repository\EffectifEnqueteursRepository;
use App\Repository\ExerciceRepository;
use App\Repository\IndicateurRepository;
use App\Repository\IndicateurValeurRepository;
use App\Repository\JournalActiviteRepository;
use App\Repository\PersonnelRepository;
use App\Repository\PieceJointeRepository;
use App\Repository\PointFocalRepository;
use App\Repository\ProjetRepository;
use App\Repository\RoleRepository;
use App\Repository\StructureRepository;
use App\Repository\UtilisateurRepository;
use App\Security\IndicateurAccesResolver;
use App\Security\PermissionsResolver;
use App\Security\PieceJointeAccesResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Extractions Excel de chaque écran (observations des utilisateurs,
 * octobre 2026). Chaque jeu de données exige sa propre permission :
 * exports.generer pour les données du projet, et un droit d'écran
 * spécifique pour les données sensibles — rh.consulter pour le personnel,
 * admin.utilisateurs pour les comptes, admin.roles pour les rôles,
 * journal.consulter pour le journal. La portée par structure s'applique
 * comme à l'écran : un utilisateur n'extrait que ce qu'il peut consulter.
 */
#[Route('/api/v1/exports/donnees')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ExportDonneesController
{
    use ApiControllerHelpersTrait;

    /** Plafond du journal exporté : au-delà, filtrer par dates. */
    private const JOURNAL_MAX = 20000;

    public function __construct(
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly ProjetRepository $projets,
        private readonly ExerciceRepository $exercices,
        private readonly ArbreReader $arbreReader,
        private readonly SyntheseReader $syntheseReader,
        private readonly AlertesReader $alertesReader,
        private readonly ActiviteRecenteReader $activiteRecenteReader,
        private readonly ExecutionSyntheseReader $executionReader,
        private readonly ActiviteRepository $activites,
        private readonly ComposanteRepository $composantes,
        private readonly IndicateurRepository $indicateurs,
        private readonly IndicateurValeurRepository $valeurs,
        private readonly IndicateurAccesResolver $indicateurAcces,
        private readonly PieceJointeRepository $piecesJointes,
        private readonly PieceJointeAccesResolver $pieceJointeAcces,
        private readonly PersonnelRepository $personnels,
        private readonly EffectifEnqueteursRepository $effectifs,
        private readonly StructureRepository $structures,
        private readonly PointFocalRepository $pointsFocaux,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly RoleRepository $roles,
        private readonly JournalActiviteRepository $journal,
    ) {
    }

    #[Route('/tableau-de-bord.xlsx', name: 'app_export_donnees_tableau_de_bord', methods: ['GET'])]
    public function tableauDeBord(Request $request): Response
    {
        $this->exigerExport();
        [$projet, $exercice] = $this->projetEtExercice($request);

        $synthese = $this->syntheseReader->lire((int) $projet->getId(), (int) $exercice->getId());
        $alertes = $this->alertesReader->lire((int) $projet->getId(), $exercice);

        $tableur = (new TableurExport("exercice {$exercice->getAnnee()}"))
            ->feuille('Synthèse', "Synthèse du projet — exercice {$exercice->getAnnee()}", [
                ['Indicateur', TableurExport::TEXTE, 30], ['Valeur', TableurExport::FCFA, 20], ['Part du budget notifié', TableurExport::TAUX, 20],
            ], [
                ['Budget notifié (FCFA)', $synthese['notifie'], null],
                ['Engagements (FCFA)', $synthese['engage'], TableurExport::taux($synthese['tauxEngagement'])],
                ['Dépenses exécutées (FCFA)', $synthese['depense'], TableurExport::taux($synthese['tauxExecution'])],
                ['Solde disponible (FCFA)', $synthese['disponible'], TableurExport::taux(GrandeursDerivees::taux($synthese['disponible'], $synthese['notifie']))],
            ]);
        $this->feuilleArbre($tableur, $projet, $exercice);
        $tableur->feuille('Points d\'attention', 'Points d\'attention', [
            ['Code', TableurExport::TEXTE, 10], ['Activité', TableurExport::TEXTE, 50], ['Structure', TableurExport::TEXTE, 12],
            ['Statut', TableurExport::TEXTE, 14], ['Vigilance', TableurExport::TEXTE, 14], ["Taux d'exécution", TableurExport::TAUX, 14],
            ['Taux attendu', TableurExport::TAUX, 14], ['Fin prévue', TableurExport::DATE, 14],
        ], array_map(static fn (array $a) => [
            $a['code'], $a['libelle'], $a['structure'], LibellesExport::statut($a['statut']), ucfirst(str_replace('_', ' ', (string) $a['vigilance'])),
            TableurExport::taux($a['tauxExecution']), TableurExport::taux($a['tauxAttendu']), $a['dateFinPrevue'],
        ], $alertes));
        $tableur->feuille('Activité récente', 'Activité récente', [
            ['Date', TableurExport::DATE_HEURE, 18], ['Auteur', TableurExport::TEXTE, 24], ['Action', TableurExport::TEXTE, 30],
            ['Code', TableurExport::TEXTE, 10], ['Cible', TableurExport::TEXTE, 50],
        ], array_map(static fn (array $e) => [
            $e['horodatage'], $e['utilisateur'], $e['libelleAction'], $e['code'], $e['libelleCible'],
        ], $this->activiteRecenteReader->lire((int) $projet->getId(), 200)));

        return $tableur->reponse("tableau-de-bord-{$exercice->getAnnee()}.xlsx");
    }

    #[Route('/composantes.xlsx', name: 'app_export_donnees_composantes', methods: ['GET'])]
    public function composantes(Request $request): Response
    {
        $this->exigerExport();
        [$projet, $exercice] = $this->projetEtExercice($request);
        $tableur = new TableurExport("exercice {$exercice->getAnnee()}");
        $this->feuilleArbre($tableur, $projet, $exercice);

        return $tableur->reponse("composantes-{$exercice->getAnnee()}.xlsx");
    }

    #[Route('/activites/{uuid}.xlsx', name: 'app_export_donnees_activite', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function activite(string $uuid): Response
    {
        $this->exigerExport();
        // Le filtre de portée s'applique à cette lecture : hors périmètre, 404.
        $activite = $this->activites->findOneBy(['uuid' => $this->uuidOuNull($uuid)]);
        if (!$activite instanceof Activite || $activite->estSupprime()) {
            throw new NotFoundHttpException('Activité introuvable.');
        }

        $etapes = [];
        foreach ($activite->getEtapesPropres() as $etape) {
            $etapes[] = $this->ligneEtape('Activité', $etape);
        }
        foreach ($activite->getSousActivitesActives() as $ligne) {
            foreach ($ligne->getEtapesActives() as $etape) {
                $etapes[] = $this->ligneEtape($ligne->getLibelle(), $etape);
            }
        }

        $dotations = [];
        $engagements = [];
        $depenses = [];
        $cibles = [$activite, ...$activite->getSousActivitesActives()->toArray()];
        foreach ($cibles as $cible) {
            $nomCible = $cible instanceof SousActivite ? $cible->getLibelle() : 'Activité (hors lignes)';
            foreach ($cible->getDotations() as $d) {
                $dotations[] = [$nomCible, $d->getExercice()->getAnnee(), $d->getMontant()->versFcfa(), $d->getMontantValideCopil()?->versFcfa(), $d->getCommentaireRevision()];
            }
            foreach ($cible->getEngagements() as $e) {
                if ($e->estSupprime()) {
                    continue;
                }
                $engagements[] = [$nomCible, $e->getExercice()->getAnnee(), $e->getReferenceMarche(), LibellesExport::nature($e->getNature()->value), $e->getMontant()->versFcfa(), $e->getDateEngagement(), LibellesExport::statut($e->getStatut()->value), $e->getCreePar()->getNomComplet(), $e->getValidePar()?->getNomComplet()];
                foreach ($e->getDepenses() as $dp) {
                    if (!$dp->estSupprime()) {
                        $depenses[] = [$nomCible, $e->getReferenceMarche() ?? 'Sans référence', $dp->getReferencePaiement(), $dp->getMontant()->versFcfa(), $dp->getDateDepense(), LibellesExport::statut($dp->getStatut()->value), $dp->getCreePar()->getNomComplet(), $dp->getValidePar()?->getNomComplet()];
                    }
                }
            }
        }

        $titre = "{$activite->getCode()} — {$activite->getLibelle()}";
        $tableur = (new TableurExport($titre))
            ->feuille('Fiche', $titre, [['Rubrique', TableurExport::TEXTE, 28], ['Valeur', TableurExport::TEXTE, 70]], [
                ['Code', $activite->getCode()],
                ['Libellé', $activite->getLibelle()],
                ['Sous-composante', $activite->getSousComposante()->getCode().' — '.$activite->getSousComposante()->getLibelle()],
                ['Structure responsable', $activite->getStructureResponsable()->getSigle()],
                ['Statut', LibellesExport::statut($activite->getStatut()->value)],
                ['Progression', $this->pourcentage(ProgressionActiviteCalculator::calculer($activite))],
                ['Étapes achevées', ProgressionActiviteCalculator::etapesAchevees($activite).' / '.$activite->getEtapesActives()->count()],
            ])
            ->feuille('Lignes', 'Lignes d\'activité', [
                ['Ligne', TableurExport::TEXTE, 60], ['Statut', TableurExport::TEXTE, 14], ['Échéance', TableurExport::DATE, 14],
                ['Progression', TableurExport::TAUX, 14], ['Étapes achevées', TableurExport::ENTIER, 14], ['Étapes', TableurExport::ENTIER, 10],
            ], array_map(static fn (SousActivite $l) => [
                $l->getLibelle(), LibellesExport::statut($l->getStatut()->value), $l->getEcheance(),
                TableurExport::taux(ProgressionActiviteCalculator::calculerLigne($l)), ProgressionActiviteCalculator::etapesAcheveesLigne($l), $l->getEtapesActives()->count(),
            ], array_values($activite->getSousActivitesActives()->toArray())))
            ->feuille('Étapes', 'Étapes', [
                ['Rattachement', TableurExport::TEXTE, 40], ['Ordre', TableurExport::ENTIER, 8], ['Étape', TableurExport::TEXTE, 34],
                ['Avancement', TableurExport::TAUX, 12], ['Valeur actuelle', TableurExport::TEXTE, 14], ['Mise à jour', TableurExport::DATE, 14], ['Observation', TableurExport::TEXTE, 50],
            ], $etapes)
            ->feuille('Dotations', 'Dotations', [
                ['Cible', TableurExport::TEXTE, 50], ['Exercice', TableurExport::ENTIER, 10], ['Notifié (FCFA)', TableurExport::FCFA, 18],
                ['Validé COPIL (FCFA)', TableurExport::FCFA, 18], ['Commentaire', TableurExport::TEXTE, 40],
            ], $dotations)
            ->feuille('Engagements', 'Engagements', [
                ['Cible', TableurExport::TEXTE, 46], ['Exercice', TableurExport::ENTIER, 10], ['Référence', TableurExport::TEXTE, 18],
                ['Nature', TableurExport::TEXTE, 26], ['Montant (FCFA)', TableurExport::FCFA, 18], ["Date d'engagement", TableurExport::DATE, 16],
                ['Statut', TableurExport::TEXTE, 12], ['Saisi par', TableurExport::TEXTE, 22], ['Validé par', TableurExport::TEXTE, 22],
            ], $engagements)
            ->feuille('Dépenses', 'Dépenses', [
                ['Cible', TableurExport::TEXTE, 46], ['Engagement', TableurExport::TEXTE, 18], ['Référence de paiement', TableurExport::TEXTE, 20],
                ['Montant (FCFA)', TableurExport::FCFA, 18], ['Date', TableurExport::DATE, 14], ['Statut', TableurExport::TEXTE, 12],
                ['Saisi par', TableurExport::TEXTE, 22], ['Validé par', TableurExport::TEXTE, 22],
            ], $depenses);

        return $tableur->reponse("activite-{$activite->getCode()}.xlsx");
    }

    #[Route('/execution.xlsx', name: 'app_export_donnees_execution', methods: ['GET'])]
    public function execution(Request $request): Response
    {
        $this->exigerExport();
        [$projet, $exercice] = $this->projetEtExercice($request);

        $debut = $exercice->getDateDebut();
        $fin = $exercice->getDateFin();
        $periode = "exercice {$exercice->getAnnee()}";
        $trimestre = (int) $request->query->get('trimestre', 0);
        $mois = (int) $request->query->get('mois', 0);
        if ($trimestre >= 1 && $trimestre <= 4) {
            $debut = new \DateTimeImmutable(\sprintf('%d-%02d-01', $exercice->getAnnee(), ($trimestre - 1) * 3 + 1));
            $fin = $debut->modify('+3 months -1 day');
            $periode = "T{$trimestre} {$exercice->getAnnee()}";
        } elseif ($mois >= 1 && $mois <= 12) {
            $debut = new \DateTimeImmutable(\sprintf('%d-%02d-01', $exercice->getAnnee(), $mois));
            $fin = $debut->modify('last day of this month');
            $periode = $debut->format('m/Y');
        }

        $donnees = $this->executionReader->lire(
            (int) $projet->getId(),
            (int) $exercice->getId(),
            $debut,
            $fin,
            $this->uuidOuNull((string) $request->query->get('composante', ''))?->toRfc4122(),
            $this->uuidOuNull((string) $request->query->get('activite', ''))?->toRfc4122(),
            $this->uuidOuNull((string) $request->query->get('ligne', ''))?->toRfc4122(),
        );
        $niveau = ['composante' => 'Composante', 'activite' => 'Activité', 'ligne' => 'Ligne du PTBA'][$donnees['niveau']];
        $lignes = array_map(fn (array $l) => $this->ligneExecution($l['code'], $l['libelle'], $l), $donnees['lignes']);
        $lignes[] = $this->ligneExecution('', 'Total', $donnees['totaux']);

        return (new TableurExport($periode))
            ->feuille('Exécution', "Exécution physique et financière — {$periode}", [
                ['Code', TableurExport::TEXTE, 10], [$niveau, TableurExport::TEXTE, 50], ['Lignes', TableurExport::ENTIER, 10],
                ['Réalisées', TableurExport::ENTIER, 10], ['Exécution physique', TableurExport::TAUX, 14], ['Avancement moyen', TableurExport::TAUX, 14],
                ['Budget (FCFA)', TableurExport::FCFA, 18], ['Engagé (FCFA)', TableurExport::FCFA, 18], ['Dépensé (FCFA)', TableurExport::FCFA, 18],
                ['Engagé sur la période', TableurExport::FCFA, 18], ['Dépensé sur la période', TableurExport::FCFA, 18],
                ["Taux d'engagement", TableurExport::TAUX, 14], ['Exécution financière', TableurExport::TAUX, 14], ['Taux de décaissement', TableurExport::TAUX, 14],
            ], $lignes)
            ->reponse('execution-'.str_replace([' ', '/'], '-', $periode).'.xlsx');
    }

    #[Route('/indicateurs/{uuid}.xlsx', name: 'app_export_donnees_indicateur', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function indicateur(string $uuid): Response
    {
        $indicateur = $this->indicateurs->findOneBy(['uuid' => $this->uuidOuNull($uuid)]);
        if (!$indicateur instanceof Indicateur || $indicateur->estSupprime()) {
            throw new NotFoundHttpException('Indicateur introuvable.');
        }
        // Qui consulte l'indicateur (permission ou invitation) peut en extraire la série.
        if (!$this->indicateurAcces->peutConsulter($indicateur)) {
            throw new AccessDeniedException();
        }

        $titre = "{$indicateur->getCode()} — {$indicateur->getLibelle()}";
        $lignes = [];
        foreach ($this->valeurs->lister($indicateur) as $v) {
            $desagregations = implode(' ; ', array_map(
                static fn ($d) => \sprintf('%s — %s : %s', $d->getDimension(), $d->getModalite(), $d->getValeur()),
                $v->getDesagregations()->toArray(),
            ));
            $lignes[] = [$v->getPeriode(), (float) $v->getValeur(), LibellesExport::statut($v->getStatut()->value), $v->getSaisiPar()->getNomComplet(), $v->getValidePar()?->getNomComplet(), $v->getCommentaire(), $desagregations];
        }

        return (new TableurExport($titre))
            ->feuille('Série', $titre, [
                ['Période', TableurExport::DATE, 14], ['Valeur ('.$indicateur->getUnite().')', TableurExport::DECIMAL, 16], ['Statut', TableurExport::TEXTE, 12],
                ['Saisie par', TableurExport::TEXTE, 22], ['Validée par', TableurExport::TEXTE, 22], ['Commentaire', TableurExport::TEXTE, 40], ['Désagrégations', TableurExport::TEXTE, 50],
            ], $lignes)
            ->reponse("indicateur-{$indicateur->getCode()}.xlsx");
    }

    #[Route('/documents.xlsx', name: 'app_export_donnees_documents', methods: ['GET'])]
    public function documents(Request $request): Response
    {
        $this->exigerExport();
        $utilisateur = $this->utilisateurCourant();
        $projet = $this->projet();

        $composante = null;
        $composanteUuid = $request->query->get('composante');
        if (\is_string($composanteUuid) && '' !== $composanteUuid) {
            $composante = $this->composantes->findOneBy(['uuid' => $this->uuidOuNull($composanteUuid)]);
            if (null === $composante) {
                throw new NotFoundHttpException('Composante introuvable.');
            }
        }

        $documents = $this->piecesJointes->filtrer(
            null !== $composante ? 'composante' : 'projet',
            (int) (null !== $composante ? $composante->getId() : $projet->getId()),
            \is_string($q = $request->query->get('q')) && '' !== trim($q) ? trim($q) : null,
            \is_string($du = $request->query->get('du')) && '' !== $du ? new \DateTimeImmutable($du) : null,
            \is_string($au = $request->query->get('au')) && '' !== $au ? new \DateTimeImmutable($au.' 23:59:59') : null,
            FamilleDocument::tryFrom((string) $request->query->get('famille', '')),
            TypeDocument::tryFrom((string) $request->query->get('type', '')),
        );

        return (new TableurExport(null !== $composante ? 'Composante '.$composante->getCode() : 'Documents du projet'))
            ->feuille('Documents', 'Bibliothèque documentaire', [
                ['Intitulé', TableurExport::TEXTE, 40], ['Nom du fichier', TableurExport::TEXTE, 36], ['Type', TableurExport::TEXTE, 24],
                ['Format', TableurExport::TEXTE, 14], ['Taille (Ko)', TableurExport::ENTIER, 12], ['Déposé par', TableurExport::TEXTE, 22], ['Déposé le', TableurExport::DATE_HEURE, 18],
            ], array_map(static fn (PieceJointe $p) => [
                $p->getLibelle(), $p->getNomFichier(), $p->libelleTypeDocument() ?? 'Non précisé', FamilleDocument::depuisMime($p->getMime())->libelle(),
                (int) ceil($p->getTailleOctets() / 1024), $p->getCreePar()->getNomComplet(), $p->getCreeLe(),
            ], array_values(array_filter($documents, fn (PieceJointe $p) => $this->pieceJointeAcces->estAccessible($p, $utilisateur)))))
            ->reponse('documents.xlsx');
    }

    #[Route('/personnel.xlsx', name: 'app_export_donnees_personnel', methods: ['GET'])]
    public function personnel(): Response
    {
        $this->exigerGlobale('rh.consulter');
        $maintenant = new \DateTimeImmutable('today');

        $agents = [];
        $contrats = [];
        foreach ($this->personnels->listerNonSupprimes() as $p) {
            $courant = $p->getContratCourant();
            $agents[] = [
                $p->getPrenom(), $p->getNom(), 'F' === $p->getSexe()->value ? 'Féminin' : 'Masculin', $p->getFonction(), $p->getProfil(), $p->getSpecialite(),
                $p->getStructure()?->getSigle(), $p->getActivite()?->getCode(), $p->getEmail(), $p->getTelephone(),
                $courant?->getDateDebut(), $courant?->getDateFin(), $courant?->joursAvantEcheance($maintenant),
            ];
            foreach ($p->getContratsActifs() as $c) {
                $contrats[] = [$p->getNomComplet(), $c->getType(), $c->getDateDebut(), $c->getDateFin(), $c->dureeEnJours(), $c->getObservation()];
            }
        }

        return (new TableurExport('personnel technique'))
            ->feuille('Personnel', 'Personnel technique', [
                ['Prénom', TableurExport::TEXTE, 18], ['Nom', TableurExport::TEXTE, 18], ['Sexe', TableurExport::TEXTE, 10], ['Fonction', TableurExport::TEXTE, 30],
                ['Profil', TableurExport::TEXTE, 12], ['Spécialité', TableurExport::TEXTE, 20], ['Structure', TableurExport::TEXTE, 12], ['Opération', TableurExport::TEXTE, 10],
                ['E-mail', TableurExport::TEXTE, 28], ['Téléphone', TableurExport::TEXTE, 16], ['Début du contrat', TableurExport::DATE, 14],
                ['Fin du contrat', TableurExport::DATE, 14], ['Jours avant échéance', TableurExport::ENTIER, 14],
            ], $agents)
            ->feuille('Contrats', 'Contrats', [
                ['Agent', TableurExport::TEXTE, 28], ['Type', TableurExport::TEXTE, 22], ['Début', TableurExport::DATE, 14],
                ['Fin', TableurExport::DATE, 14], ['Durée (jours)', TableurExport::ENTIER, 12], ['Observation', TableurExport::TEXTE, 40],
            ], $contrats)
            ->reponse('personnel-technique.xlsx');
    }

    #[Route('/effectifs-enqueteurs.xlsx', name: 'app_export_donnees_effectifs', methods: ['GET'])]
    public function effectifs(): Response
    {
        $this->exigerGlobale('rh.consulter');

        $lignes = [];
        foreach ($this->effectifs->listerNonSupprimes() as $e) {
            $lignes[] = [$e->getActivite()->getCode(), $e->getActivite()->getLibelle(), $e->getLibelle(), $e->getAnnee(), $e->getNombreFemmes(), $e->getNombreHommes(), $e->effectifTotal(), TableurExport::taux($e->partFemmes()), $e->getDateDebut(), $e->getDateFin(), $e->dureeEnJours()];
        }

        return (new TableurExport('enquêteurs'))
            ->feuille('Enquêteurs', "Effectifs d'enquêteurs", [
                ['Opération', TableurExport::TEXTE, 10], ['Libellé de l\'opération', TableurExport::TEXTE, 46], ['Collecte', TableurExport::TEXTE, 22],
                ['Année', TableurExport::ENTIER, 8], ['Femmes', TableurExport::ENTIER, 10], ['Hommes', TableurExport::ENTIER, 10], ['Total', TableurExport::ENTIER, 10],
                ['Part des femmes', TableurExport::TAUX, 12], ['Début', TableurExport::DATE, 14], ['Fin', TableurExport::DATE, 14], ['Durée (jours)', TableurExport::ENTIER, 12],
            ], $lignes)
            ->reponse('effectifs-enqueteurs.xlsx');
    }

    #[Route('/parties-prenantes.xlsx', name: 'app_export_donnees_parties_prenantes', methods: ['GET'])]
    public function partiesPrenantes(): Response
    {
        $this->exigerExport();

        $structures = array_map(static fn ($s) => [$s->getSigle(), $s->getDenomination(), LibellesExport::typeStructure($s->getType()->value), $s->getEmail(), $s->getTelephone(), $s->getAdresse(), LibellesExport::ouiNon($s->estActif())], $this->structures->listerNonSupprimees());
        $focaux = [];
        foreach ($this->pointsFocaux->findAll() as $pf) {
            $focaux[] = [$pf->getStructure()->getSigle(), $pf->getNomComplet(), $pf->getFonction(), $pf->getEmail(), $pf->getTelephone(), LibellesExport::ouiNon($pf->estPrincipal()), $pf->getDateDesignation(), LibellesExport::ouiNon($pf->estActif())];
        }
        usort($focaux, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return (new TableurExport('parties prenantes'))
            ->feuille('Structures', 'Parties prenantes', [
                ['Sigle', TableurExport::TEXTE, 12], ['Dénomination', TableurExport::TEXTE, 50], ['Type', TableurExport::TEXTE, 22],
                ['E-mail', TableurExport::TEXTE, 28], ['Téléphone', TableurExport::TEXTE, 16], ['Adresse', TableurExport::TEXTE, 30], ['Active', TableurExport::TEXTE, 8],
            ], $structures)
            ->feuille('Points focaux', 'Points focaux', [
                ['Structure', TableurExport::TEXTE, 12], ['Prénom et nom', TableurExport::TEXTE, 28], ['Fonction', TableurExport::TEXTE, 28],
                ['E-mail', TableurExport::TEXTE, 28], ['Téléphone', TableurExport::TEXTE, 16], ['Principal', TableurExport::TEXTE, 10],
                ['Désignation', TableurExport::DATE, 14], ['Actif', TableurExport::TEXTE, 8],
            ], $focaux)
            ->reponse('parties-prenantes.xlsx');
    }

    #[Route('/comptes.xlsx', name: 'app_export_donnees_comptes', methods: ['GET'])]
    public function comptes(): Response
    {
        $this->exigerGlobale('admin.utilisateurs');

        $lignes = [];
        foreach ($this->utilisateurs->findBy(['supprimeLe' => null], ['nom' => 'ASC', 'prenom' => 'ASC']) as $u) {
            $roles = [];
            foreach ($u->getAttributionsRoles() as $attribution) {
                $roles[] = $attribution->getRole()->getLibelle().(null !== $attribution->getStructure() ? ' ('.$attribution->getStructure()->getSigle().')' : '');
            }
            $lignes[] = [$u->getPrenom(), $u->getNom(), $u->getEmail(), $u->getFonction(), $u->getStructure()?->getSigle(), implode(', ', $roles), LibellesExport::ouiNon($u->estActif()), LibellesExport::ouiNon($u->doitChangerMdp()), $u->getDerniereConnexion()];
        }

        return (new TableurExport('comptes utilisateurs'))
            ->feuille('Comptes', 'Comptes utilisateurs', [
                ['Prénom', TableurExport::TEXTE, 18], ['Nom', TableurExport::TEXTE, 18], ['E-mail', TableurExport::TEXTE, 30], ['Fonction', TableurExport::TEXTE, 28],
                ['Structure', TableurExport::TEXTE, 12], ['Rôles', TableurExport::TEXTE, 40], ['Actif', TableurExport::TEXTE, 8],
                ['Mot de passe temporaire', TableurExport::TEXTE, 14], ['Dernière connexion', TableurExport::DATE_HEURE, 18],
            ], $lignes)
            ->reponse('comptes-utilisateurs.xlsx');
    }

    #[Route('/roles.xlsx', name: 'app_export_donnees_roles', methods: ['GET'])]
    public function roles(): Response
    {
        $this->exigerGlobale('admin.roles');

        $lignes = [];
        foreach ($this->roles->findBy([], ['code' => 'ASC']) as $role) {
            foreach ($role->getPermissions() as $permission) {
                $lignes[] = [$role->getLibelle(), $role->getCode(), $permission->getCode(), $permission->getLibelle()];
            }
        }

        return (new TableurExport('rôles et permissions'))
            ->feuille('Rôles', 'Rôles et permissions', [
                ['Rôle', TableurExport::TEXTE, 30], ['Code du rôle', TableurExport::TEXTE, 28], ['Permission', TableurExport::TEXTE, 30], ['Description', TableurExport::TEXTE, 50],
            ], $lignes)
            ->reponse('roles-permissions.xlsx');
    }

    #[Route('/journal.xlsx', name: 'app_export_donnees_journal', methods: ['GET'])]
    public function journal(Request $request): Response
    {
        $this->exigerGlobale('journal.consulter');

        $qb = $this->journal->createQueryBuilder('j')->leftJoin('j.utilisateur', 'u')->addSelect('u')->orderBy('j.creeLe', 'DESC');
        if (\is_string($entite = $request->query->get('entite')) && '' !== $entite) {
            $qb->andWhere('j.entiteType = :entite')->setParameter('entite', $entite);
        }
        if (\is_string($auteur = $request->query->get('utilisateur')) && '' !== $auteur) {
            $qb->andWhere('u.uuid = :auteur')->setParameter('auteur', $this->uuidOuNull($auteur));
        }
        if (\is_string($du = $request->query->get('du')) && '' !== $du) {
            $qb->andWhere('j.creeLe >= :du')->setParameter('du', new \DateTimeImmutable($du));
        }
        if (\is_string($au = $request->query->get('au')) && '' !== $au) {
            $qb->andWhere('j.creeLe <= :au')->setParameter('au', new \DateTimeImmutable($au.' 23:59:59'));
        }
        /** @var list<JournalActivite> $entrees */
        $entrees = $qb->setMaxResults(self::JOURNAL_MAX)->getQuery()->getResult();

        $json = static fn (?array $etat): ?string => null === $etat ? null : (json_encode($etat, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: null);

        return (new TableurExport(\count($entrees) >= self::JOURNAL_MAX ? 'limité aux '.self::JOURNAL_MAX.' entrées les plus récentes' : ''))
            ->feuille('Journal', "Journal d'activité", [
                ['Date', TableurExport::DATE_HEURE, 18], ['Auteur', TableurExport::TEXTE, 24], ['Action', TableurExport::TEXTE, 14],
                ['Type d\'entité', TableurExport::TEXTE, 18], ['Identifiant', TableurExport::ENTIER, 12], ['Avant', TableurExport::TEXTE, 50],
                ['Après', TableurExport::TEXTE, 50], ['Adresse IP', TableurExport::TEXTE, 16],
            ], array_map(static fn (JournalActivite $e) => [
                $e->getCreeLe(), $e->getUtilisateur()?->getNomComplet(), LibellesExport::action($e->getAction()), str_replace('_', ' ', $e->getEntiteType()),
                $e->getEntiteId(), $json($e->getAvant()), $json($e->getApres()), $e->getAdresseIp(),
            ], $entrees))
            ->reponse('journal-activite.xlsx');
    }

    private function feuilleArbre(TableurExport $tableur, Projet $projet, Exercice $exercice): void
    {
        $lignes = [];
        $montants = static fn (array $n): array => [
            $n['notifie'], $n['engage'], $n['depense'],
            GrandeursDerivees::disponible($n['notifie'], $n['engage']),
            TableurExport::taux(GrandeursDerivees::taux($n['depense'], $n['notifie'])),
        ];
        foreach ($this->arbreReader->lire((int) $projet->getId(), (int) $exercice->getId(), 4) as $c) {
            $lignes[] = ['Composante', $c['code'], $c['libelle'], null, null, null, ...$montants($c)];
            foreach ($c['sousComposantes'] ?? [] as $sc) {
                $lignes[] = ['Sous-composante', $sc['code'], $sc['libelle'], null, null, null, ...$montants($sc)];
                foreach ($sc['activites'] ?? [] as $a) {
                    $lignes[] = ['Activité', $a['code'], $a['libelle'], $a['structureResponsable']['sigle'] ?? null, LibellesExport::statut($a['statut']), null !== $a['avancement'] ? $a['avancement'] / 100 : null, ...$montants($a)];
                    foreach ($a['sousActivites'] ?? [] as $sa) {
                        $lignes[] = ['Ligne', $a['code'], $sa['libelle'], null, LibellesExport::statut($sa['statut']), null !== ($sa['avancement'] ?? null) ? $sa['avancement'] / 100 : null, ...$montants($sa)];
                    }
                }
            }
        }

        $tableur->feuille('Arbre d\'exécution', "Arbre d'exécution — exercice {$exercice->getAnnee()}", [
            ['Niveau', TableurExport::TEXTE, 16], ['Code', TableurExport::TEXTE, 10], ['Libellé', TableurExport::TEXTE, 60], ['Structure', TableurExport::TEXTE, 12],
            ['Statut', TableurExport::TEXTE, 12], ['Avancement', TableurExport::TAUX, 12], ['Notifié (FCFA)', TableurExport::FCFA, 18], ['Engagé (FCFA)', TableurExport::FCFA, 18],
            ['Dépensé (FCFA)', TableurExport::FCFA, 18], ['Disponible (FCFA)', TableurExport::FCFA, 18], ["Taux d'exécution", TableurExport::TAUX, 14],
        ], $lignes);
    }

    /** @return list<mixed> */
    private function ligneEtape(string $rattachement, EtapeActivite $etape): array
    {
        return [$rattachement, $etape->getOrdre(), $etape->getLibelle(), $etape->getAvancement() / 100, $etape->getValeurActuelle(), $etape->getDateMiseAJour(), $etape->getObservation()];
    }

    /**
     * @param array<string, mixed> $l
     *
     * @return list<mixed>
     */
    private function ligneExecution(string $code, string $libelle, array $l): array
    {
        return [
            $code, $libelle, $l['unites'], $l['realisees'], TableurExport::taux($l['tauxPhysique']), TableurExport::taux($l['avancementMoyen']),
            $l['budget'], $l['engage'], $l['depense'], $l['engagePeriode'], $l['depensePeriode'],
            TableurExport::taux($l['tauxEngagement']), TableurExport::taux($l['tauxExecution']), TableurExport::taux($l['tauxDecaissement']),
        ];
    }

    private function pourcentage(?float $taux): string
    {
        return null === $taux ? '—' : number_format($taux * 100, 1, ',', ' ').' %';
    }

    /**
     * exports.generer détenue, globalement ou sur une structure : les
     * lecteurs appliquent ensuite la portée, un point focal n'extrait que ce
     * qu'il consulte à l'écran.
     */
    private function exigerExport(): void
    {
        if (!$this->permissionsResolver->pour($this->utilisateurCourant())->possede('exports.generer')) {
            throw new AccessDeniedException();
        }
    }

    private function exigerGlobale(string $permission): void
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, $permission);
    }

    private function utilisateurCourant(): Utilisateur
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }

        return $utilisateur;
    }

    private function projet(): Projet
    {
        return $this->projets->findOneBy([]) ?? throw new NotFoundHttpException('Projet introuvable.');
    }

    /** @return array{0: Projet, 1: Exercice} */
    private function projetEtExercice(Request $request): array
    {
        $projet = $this->projet();
        $annee = $request->query->get('exercice');
        $exercice = \is_string($annee) && '' !== $annee
            ? $this->exercices->trouverParAnnee($projet, (int) $annee)
            : $this->exercices->courantOuPlusRecent($projet);
        if (null === $exercice) {
            throw new NotFoundHttpException('Exercice introuvable.');
        }

        return [$projet, $exercice];
    }
}
