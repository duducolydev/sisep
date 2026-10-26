<?php

declare(strict_types=1);

namespace App\Import;

use App\Classeur\Normalisation;
use App\Entity\Activite;
use App\Entity\Engagement;
use App\Entity\Exercice;
use App\Entity\SousActivite;
use App\Entity\Utilisateur;
use App\Repository\DotationRepository;
use App\Repository\EngagementRepository;
use App\Repository\ExerciceRepository;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Import des engagements depuis un extrait de la comptabilité ou un
 * tableau de suivi (demande de l'UGP, octobre 2026).
 *
 * Les engagements arrivent **en brouillon**, au nom de la personne qui
 * importe : ils passent ensuite par la validation, qui contrôle le plafond
 * de la dotation sous verrou (§ 5.1) et refuse que l'importateur valide ses
 * propres lignes (§ 7.2). L'import n'est jamais un raccourci autour de ces
 * contrôles ; il les annonce seulement à l'aperçu.
 *
 * Un engagement est identifié par sa référence (n° d'engagement ou de
 * marché) dans son exercice : c'est elle qui rattache ensuite les dépenses.
 * Un engagement déjà présent n'est pas modifié par un nouvel import — il se
 * corrige ou s'annule dans SISEP, où chaque changement est journalisé.
 */
final class ImportEngagements implements DefinitionImport
{
    public function __construct(
        private readonly ReferentielsImport $referentiels,
        private readonly ExerciceRepository $exercices,
        private readonly DotationRepository $dotations,
        private readonly EngagementRepository $engagements,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function type(): string
    {
        return 'engagements';
    }

    public function titre(): string
    {
        return 'Engagements';
    }

    public function permission(): string
    {
        return 'budget.engagement.saisir';
    }

    public function colonnes(): array
    {
        return [
            new ColonneImport('ligne', 'Ligne budgétaire', true, 'Activité du PTBA et sa ligne, telles que dans la liste (code, puis libellé de la ligne). Le code seul suffit pour une activité sans ligne.', '3.1.2 — Ressources Numériques pour le SSN (RNSSN)', ['Ligne', 'Ligne PTBA', 'Activité', 'Code activité'], 'lignesBudgetaires', largeur: 48),
            new ColonneImport('reference', 'Référence', true, 'N° d\'engagement, de bon de commande ou de marché — unique dans l\'exercice. Les dépenses s\'y rattachent par cette référence.', 'BC-2026-014', ['N° engagement', 'Numéro', 'Référence engagement', 'Référence marché', 'N° marché'], largeur: 20),
            new ColonneImport('nature', 'Nature', true, 'Nature de l\'engagement, telle que dans la liste.', 'Fournitures', ['Nature de la dépense', 'Type'], 'natures', largeur: 30),
            new ColonneImport('montant', 'Montant (FCFA)', true, 'Montant en FCFA, sans décimales.', '12500000', ['Montant', 'Montant engagé'], largeur: 16),
            new ColonneImport('date', 'Date d\'engagement', true, 'JJ/MM/AAAA.', '15/03/2026', ['Date', 'Date engagement'], date: true, largeur: 14),
            new ColonneImport('exercice', 'Exercice', false, 'Année budgétaire. À défaut, celle de la date d\'engagement.', '2026', ['Année'], largeur: 10),
            new ColonneImport('observations', 'Observations', false, 'Objet ou commentaire libre.', 'Achat de 20 tablettes', ['Objet', 'Libellé', 'Commentaire'], largeur: 36),
        ];
    }

    public function listes(): array
    {
        return [
            'lignesBudgetaires' => $this->referentiels->lignesBudgetaires(),
            'natures' => ReferentielsImport::naturesEngagement(),
        ];
    }

    public function analyser(array $lignes, bool $miseAJour): array
    {
        $existants = [];
        foreach ($this->engagements->findBy(['supprimeLe' => null]) as $engagement) {
            if (null !== $engagement->getReferenceMarche()) {
                $existants[$this->cle($engagement->getExercice(), $engagement->getReferenceMarche())] = $engagement;
            }
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }

        $vues = [];
        /** @var array<string, int> $cumuls montant des lignes du fichier, par cible et exercice */
        $cumuls = [];
        $verdicts = [];
        foreach ($lignes as $numero => $v) {
            $erreurs = [];
            $reference = LecteurFichierImport::texte($v['reference'] ?? null);
            $libelleLigne = LecteurFichierImport::texte($v['ligne'] ?? null);
            $montant = ReferentielsImport::montant($v['montant'] ?? null, 'Montant', $erreurs);
            $libelle = trim(($reference ?? \sprintf('Ligne %d', $numero)).(null !== $montant ? ' — '.Montant::fromFcfa($montant)->formater() : ''));

            if (null === $reference) {
                $erreurs[] = 'La référence est requise : c\'est elle qui rattache ensuite les dépenses.';
            } elseif (mb_strlen($reference) > 80) {
                $erreurs[] = 'La référence tient en 80 caractères au plus.';
            }
            if (null === $libelleLigne) {
                $erreurs[] = 'La ligne budgétaire est requise.';
            }
            if (null === $v['montant'] || '' === $v['montant']) {
                $erreurs[] = 'Le montant est requis.';
            }
            $cible = $this->referentiels->cibleBudgetaire($libelleLigne, $erreurs);
            $nature = ReferentielsImport::natureEngagement(LecteurFichierImport::texte($v['nature'] ?? null), $erreurs);
            if (null === LecteurFichierImport::texte($v['nature'] ?? null)) {
                $erreurs[] = 'La nature est requise.';
            }
            $date = ReferentielsImport::date($v['date'] ?? null, 'Date d\'engagement', $erreurs);
            if (null === $date && [] === array_filter($erreurs, static fn (string $e) => str_starts_with($e, 'Date'))) {
                $erreurs[] = 'La date d\'engagement est requise.';
            }
            $annee = ReferentielsImport::entier($v['exercice'] ?? null, 'Exercice', $erreurs, 2000, 2100) ?? ($date ? (int) $date->format('Y') : null);

            $exercice = null;
            if (null !== $cible && null !== $annee) {
                $exercice = $this->exercice($cible, $annee, $erreurs);
            }
            if (null !== $cible && !$this->security->isGranted('budget.engagement.saisir', $cible)) {
                $erreurs[] = 'Cette ligne budgétaire est hors de votre périmètre de saisie.';
            }

            if ([] !== $erreurs || null === $cible || null === $exercice || null === $reference || null === $nature || null === $montant || null === $date) {
                $verdicts[] = LigneImport::erreur($numero, $libelle, $erreurs);

                continue;
            }

            $cle = $this->cle($exercice, $reference);
            if (isset($vues[$cle])) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::DOUBLON, [\sprintf('Même référence qu\'à la ligne %d pour l\'exercice %d : ligne ignorée.', $vues[$cle], $exercice->getAnnee())]);

                continue;
            }
            $vues[$cle] = $numero;

            $existant = $existants[$cle] ?? null;
            if (null !== $existant) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::EXISTANTE, [\sprintf(
                    'Engagement déjà enregistré (%s, %s) : ligne ignorée. Il se corrige ou s\'annule dans SISEP.',
                    $existant->getStatut()->value,
                    $existant->getMontant()->formater(),
                )]);

                continue;
            }

            $erreursPlafond = null;
            $messages = $this->controlePlafond($cible, $exercice, $montant, $cumuls, $erreursPlafond);
            if (null !== $erreursPlafond) {
                $verdicts[] = LigneImport::erreur($numero, $libelle, [$erreursPlafond]);

                continue;
            }

            $observations = LecteurFichierImport::texte($v['observations'] ?? null);
            $application = function () use ($cible, $exercice, $nature, $montant, $date, $reference, $observations, $utilisateur): void {
                $engagement = new Engagement($cible, $exercice, $nature, Montant::fromFcfa($montant), $date, $utilisateur);
                $engagement->setReferenceMarche($reference);
                $engagement->setObservations($observations);
                $this->em->persist($engagement);
            };
            $verdicts[] = new LigneImport($numero, $libelle, LigneImport::CREATION, ['Créé en brouillon : à valider par une autre personne.', ...$messages], $application);
        }

        return $verdicts;
    }

    /**
     * Annonce, à l'aperçu, ce que la validation dira du plafond : la ligne
     * doit avoir une dotation sur l'exercice (sans elle, l'engagement ne
     * pourrait jamais être validé), et le total engagé ne doit pas la
     * dépasser. Le contrôle qui compte reste celui de la validation.
     *
     * @param array<string, int> $cumuls
     *
     * @return list<string>
     */
    private function controlePlafond(Activite|SousActivite $cible, Exercice $exercice, int $montant, array &$cumuls, ?string &$erreur): array
    {
        $erreur = null;
        $dotation = $this->dotations->trouverPourCibleExercice($cible, $exercice);
        if (null === $dotation) {
            $erreur = \sprintf('Aucune dotation notifiée pour cette ligne en %d : l\'engagement ne pourrait pas être validé. Notifiez d\'abord la dotation.', $exercice->getAnnee());

            return [];
        }

        $cleCible = ($cible instanceof Activite ? 'a' : 'l').$cible->getId().'|'.$exercice->getId();
        $cumuls[$cleCible] = ($cumuls[$cleCible] ?? 0) + $montant;
        $total = $this->engagements->sommeValidee($cible, $exercice)->ajouter(Montant::fromFcfa($cumuls[$cleCible]));
        if ($total->estSuperieurA($dotation->getMontant())) {
            return [\sprintf(
                'Attention : avec les engagements déjà validés et ceux du fichier, la ligne atteindrait %s pour une dotation de %s — la validation sera refusée au-delà.',
                $total->formater(),
                $dotation->getMontant()->formater(),
            )];
        }

        return [];
    }

    /** @param list<string> $erreurs */
    private function exercice(Activite|SousActivite $cible, int $annee, array &$erreurs): ?Exercice
    {
        $activite = $cible instanceof Activite ? $cible : $cible->getActivite();
        $exercice = $this->exercices->trouverParAnnee($activite->getSousComposante()->getComposante()->getProjet(), $annee);
        if (null === $exercice) {
            $erreurs[] = \sprintf('Exercice %d inconnu dans SISEP.', $annee);

            return null;
        }
        if (!$exercice->estOuvert()) {
            $erreurs[] = \sprintf('L\'exercice %d est clôturé : aucune écriture budgétaire n\'est plus acceptée.', $annee);

            return null;
        }

        return $exercice;
    }

    private function cle(Exercice $exercice, string $reference): string
    {
        return $exercice->getId().'|'.Normalisation::cle($reference);
    }
}
