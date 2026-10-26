<?php

declare(strict_types=1);

namespace App\Export;

use App\Budget\GrandeursDerivees;
use App\Entity\Exercice;
use App\Entity\Indicateur;
use App\Entity\Projet;
use App\Entity\RapportPeriodique;
use App\Enum\StatutRapport;
use App\Http\IndicateurNormalizer;
use App\Indicateur\TauxAtteinteCalculator;
use App\Query\AlertesReader;
use App\Query\ArbreReader;
use App\Query\ExecutionPluriannuelleReader;
use App\Query\SyntheseReader;
use App\Repository\IndicateurRepository;
use App\Repository\RapportPeriodiqueRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Classeur de la revue à mi-parcours (demande de l'UGP, octobre 2026) :
 * tout ce que SISEP sait du projet, réuni en un fichier pour préparer le
 * rapport et la mission du bailleur.
 *
 *  1. Synthèse — durée écoulée, exécution, avancement, résultats, reporting ;
 *  2. Cadre de résultats — chaque indicateur face à son objectif
 *     intermédiaire, la référence de la mi-parcours, et à sa cible ;
 *  3. Exécution par composante et sous-composante ;
 *  4. Activités — statut, progression, budget, vigilance ;
 *  5. Exécution pluriannuelle ; 6. Engagements par nature ;
 *  7. Reporting des structures ; 8. Points d'attention.
 *
 * Aucune grandeur n'est stockée : tout est recalculé au moment de l'export
 * par les mêmes lecteurs que les écrans, et un taux sans dénominateur reste
 * vide, jamais 0 (§ 5.1). Ce que SISEP ne suit pas — passation de marchés,
 * sauvegardes, décaissements du bailleur — n'y figure pas.
 */
final class RevueMiParcoursXlsxExporter
{
    public function __construct(
        private readonly SyntheseReader $syntheseReader,
        private readonly ArbreReader $arbreReader,
        private readonly AlertesReader $alertesReader,
        private readonly ExecutionPluriannuelleReader $pluriannuelReader,
        private readonly IndicateurRepository $indicateurs,
        private readonly IndicateurNormalizer $indicateurNormalizer,
        private readonly RapportPeriodiqueRepository $rapports,
    ) {
    }

    public function exporter(Projet $projet, Exercice $exercice): Response
    {
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $synthese = $this->syntheseReader->lire((int) $projet->getId(), (int) $exercice->getId());
        $arbre = $this->arbreReader->lire((int) $projet->getId(), (int) $exercice->getId(), 4);
        $alertes = [];
        foreach ($this->alertesReader->lire((int) $projet->getId(), $exercice) as $alerte) {
            $alertes[$alerte['uuid']] = $alerte;
        }
        $pluriannuel = $this->pluriannuelReader->lire((int) $projet->getId());
        $indicateurs = array_values(array_filter($this->indicateurs->filtrer($projet, null, null), static fn (Indicateur $i) => $i->estActif()));
        $resultats = array_map($this->ligneResultat(...), $indicateurs);
        $rapports = $this->rapports->filtrer($exercice, null, null);

        $activites = [];
        foreach ($arbre as $c) {
            foreach ($c['sousComposantes'] ?? [] as $sc) {
                foreach ($sc['activites'] ?? [] as $a) {
                    $activites[] = ['composante' => $c, 'activite' => $a];
                }
            }
        }

        $tableur = new TableurExport(\sprintf('%s — exercice %d', $projet->getLibelle(), $exercice->getAnnee()));
        $tableur->feuille('Synthèse', 'Revue à mi-parcours — synthèse', [
            ['Rubrique', TableurExport::TEXTE, 46], ['Valeur', TableurExport::TEXTE, 34], ['Précision', TableurExport::TEXTE, 60],
        ], $this->synthese($projet, $exercice, $maintenant, $synthese, $pluriannuel, $activites, $alertes, $resultats, $rapports));

        $tableur->feuille('Cadre de résultats', 'Cadre de résultats — progression vers l\'objectif intermédiaire et la cible', [
            ['Code', TableurExport::TEXTE, 10], ['Type', TableurExport::TEXTE, 7], ['Indicateur', TableurExport::TEXTE, 52], ['Unité', TableurExport::TEXTE, 14],
            ['Composante', TableurExport::TEXTE, 11], ['Structure responsable', TableurExport::TEXTE, 14],
            ['Référence', TableurExport::DECIMAL, 12], ['Date de référence', TableurExport::DATE, 12],
            ['Objectif intermédiaire', TableurExport::DECIMAL, 13], ['Échéance intermédiaire', TableurExport::DATE, 12],
            ['Cible', TableurExport::DECIMAL, 12], ['Échéance cible', TableurExport::DATE, 12],
            ['Valeur actuelle (validée)', TableurExport::DECIMAL, 13], ['Date de la valeur', TableurExport::DATE, 12],
            ['Progression vers l\'objectif intermédiaire', TableurExport::TAUX, 16], ['Objectif intermédiaire', TableurExport::TEXTE, 16],
            ['Progression vers la cible', TableurExport::TAUX, 14],
            ['Valeur en attente de validation', TableurExport::DECIMAL, 14], ['Source', TableurExport::TEXTE, 30],
        ], array_map(static fn (array $r) => $r['cellules'], $resultats));

        $tableur->feuille('Exécution composantes', 'Exécution financière et physique par composante — exercice '.$exercice->getAnnee(), [
            ['Niveau', TableurExport::TEXTE, 16], ['Code', TableurExport::TEXTE, 10], ['Libellé', TableurExport::TEXTE, 56],
            ['Notifié (FCFA)', TableurExport::FCFA, 17], ['Engagé (FCFA)', TableurExport::FCFA, 17], ['Dépensé (FCFA)', TableurExport::FCFA, 17],
            ['Disponible (FCFA)', TableurExport::FCFA, 17], ['Taux d\'engagement', TableurExport::TAUX, 12], ['Taux d\'exécution', TableurExport::TAUX, 12],
            ['Progression des étapes', TableurExport::TAUX, 12], ['Activités', TableurExport::ENTIER, 10], ['Terminées', TableurExport::ENTIER, 10],
        ], $this->lignesComposantes($arbre));

        $tableur->feuille('Activités', 'Activités du PTBA — exercice '.$exercice->getAnnee(), [
            ['Code', TableurExport::TEXTE, 10], ['Activité', TableurExport::TEXTE, 56], ['Composante', TableurExport::TEXTE, 11], ['Structure', TableurExport::TEXTE, 12],
            ['Statut', TableurExport::TEXTE, 12], ['Progression des étapes', TableurExport::TAUX, 12], ['Lignes', TableurExport::ENTIER, 8],
            ['Notifié (FCFA)', TableurExport::FCFA, 17], ['Engagé (FCFA)', TableurExport::FCFA, 17], ['Dépensé (FCFA)', TableurExport::FCFA, 17],
            ['Taux d\'exécution', TableurExport::TAUX, 12], ['Vigilance', TableurExport::TEXTE, 14],
        ], array_map(static function (array $x) use ($alertes): array {
            $a = $x['activite'];
            $vigilance = $alertes[$a['uuid']]['vigilance'] ?? null;

            return [
                $a['code'], $a['libelle'], $x['composante']['code'], $a['structureResponsable']['sigle'] ?? null, LibellesExport::statut($a['statut']),
                null !== $a['avancement'] ? $a['avancement'] / 100 : null, \count($a['sousActivites'] ?? []),
                $a['notifie'], $a['engage'], $a['depense'], TableurExport::taux(GrandeursDerivees::taux((int) $a['depense'], (int) $a['notifie'])),
                match ($vigilance) {
                    'en_retard' => 'En retard', 'risque_eleve' => 'Risque élevé', default => null
                },
            ];
        }, $activites));

        $lignesAnnees = array_map(static fn (array $a) => [(string) $a['annee'], $a['notifie'], $a['depense'], $a['disponible'], TableurExport::taux($a['tauxExecution'])], $pluriannuel['annees']);
        $global = $pluriannuel['global'];
        $lignesAnnees[] = ['Total du projet', $global['notifie'], $global['depense'], $global['disponible'], TableurExport::taux($global['tauxExecution'])];
        $tableur->feuille('Exécution pluriannuelle', 'Exécution financière par exercice — exercices enregistrés dans SISEP', [
            ['Exercice', TableurExport::TEXTE, 16], ['Notifié (FCFA)', TableurExport::FCFA, 18], ['Dépensé (FCFA)', TableurExport::FCFA, 18],
            ['Disponible (FCFA)', TableurExport::FCFA, 18], ['Taux d\'exécution', TableurExport::TAUX, 14],
        ], $lignesAnnees);

        $totalEngage = array_sum(array_column($synthese['engagementsParNature'], 'montant'));
        $tableur->feuille('Engagements par nature', 'Engagements validés par nature — exercice '.$exercice->getAnnee(), [
            ['Nature', TableurExport::TEXTE, 40], ['Engagements', TableurExport::ENTIER, 12], ['Montant (FCFA)', TableurExport::FCFA, 18], ['Part', TableurExport::TAUX, 10],
        ], array_map(static fn (array $n) => [
            LibellesExport::nature($n['nature']), $n['nombre'], $n['montant'], TableurExport::taux(GrandeursDerivees::taux($n['montant'], $totalEngage)),
        ], $synthese['engagementsParNature']));

        $tableur->feuille('Reporting structures', 'Rapports trimestriels des structures — exercice '.$exercice->getAnnee(), [
            ['Structure', TableurExport::TEXTE, 16], ['Rapports attendus', TableurExport::ENTIER, 12], ['Reçus', TableurExport::ENTIER, 10],
            ['En retard', TableurExport::ENTIER, 10], ['Dernière transmission', TableurExport::DATE, 14],
        ], $this->lignesReporting($rapports, $maintenant));

        $tableur->feuille('Points d\'attention', 'Activités en retard ou à risque — exercice '.$exercice->getAnnee(), [
            ['Code', TableurExport::TEXTE, 10], ['Activité', TableurExport::TEXTE, 56], ['Structure', TableurExport::TEXTE, 12], ['Vigilance', TableurExport::TEXTE, 14],
            ['Taux d\'exécution', TableurExport::TAUX, 12], ['Rythme attendu', TableurExport::TAUX, 12], ['Fin prévue', TableurExport::DATE, 12],
        ], array_map(static fn (array $a) => [
            $a['code'], $a['libelle'], $a['structure'], 'en_retard' === $a['vigilance'] ? 'En retard' : 'Risque élevé',
            TableurExport::taux($a['tauxExecution']), TableurExport::taux($a['tauxAttendu']), $a['dateFinPrevue'],
        ], array_values($alertes)));

        return $tableur->reponse(\sprintf('revue-mi-parcours-%s.xlsx', $maintenant->format('Y-m-d')));
    }

    /**
     * Un indicateur face à son objectif intermédiaire et à sa cible — même
     * calcul que le taux d'atteinte (TauxAtteinteCalculator), l'objectif
     * intermédiaire tenant lieu de cible.
     *
     * @return array{type: string, actuel: bool, intermediaire: float|null, atteint: bool|null, enAttente: bool, cellules: list<mixed>}
     */
    private function ligneResultat(Indicateur $indicateur): array
    {
        $vue = $this->indicateurNormalizer->normaliser($indicateur);
        $actuel = \is_string($vue['actuel'] ?? null) ? $vue['actuel'] : null;
        $intermediaire = $indicateur->getValeurIntermediaire();
        $versIntermediaire = null !== $intermediaire
            ? TauxAtteinteCalculator::calculer($indicateur->getSens(), $indicateur->getValeurReference(), $intermediaire, $actuel)
            : null;
        $versCible = TauxAtteinteCalculator::calculer($indicateur->getSens(), $indicateur->getValeurReference(), $indicateur->getValeurCible(), $actuel);
        $atteint = null === $intermediaire || null === $versIntermediaire ? null : $versIntermediaire >= 1.0;
        $enAttente = \is_array($vue['enAttente'] ?? null) ? $vue['enAttente'] : null;

        return [
            'type' => $indicateur->getType()->value,
            'actuel' => null !== $actuel,
            'intermediaire' => $versIntermediaire,
            'atteint' => $atteint,
            'enAttente' => null !== $enAttente,
            'cellules' => [
                $indicateur->getCode(), $indicateur->getType()->value, $indicateur->getLibelle(), $indicateur->getUnite(),
                $indicateur->getComposante()?->getCode(), $indicateur->getStructureResponsable()?->getSigle(),
                $indicateur->getValeurReference(), $indicateur->getDateReference(),
                $intermediaire, $indicateur->getDateIntermediaire(),
                $indicateur->getValeurCible(), $indicateur->getDateCible(),
                $actuel, \is_string($vue['periodeActuelle'] ?? null) ? $vue['periodeActuelle'] : null,
                TableurExport::taux($versIntermediaire),
                match (true) {
                    null === $intermediaire => 'Non défini',
                    null === $actuel => 'Non renseigné',
                    true === $atteint => 'Atteint',
                    default => 'Non atteint',
                },
                TableurExport::taux($versCible),
                $enAttente['valeur'] ?? null,
                $indicateur->getSource(),
            ],
        ];
    }

    /**
     * @param array<string, mixed>                                                    $synthese
     * @param array{annees: list<array<string, mixed>>, global: array<string, mixed>} $pluriannuel
     * @param list<array<string, mixed>>                                              $activites
     * @param array<string, array<string, mixed>>                                     $alertes
     * @param list<array<string, mixed>>                                              $resultats
     * @param list<RapportPeriodique>                                                 $rapports
     *
     * @return list<list<string|null>>
     */
    private function synthese(Projet $projet, Exercice $exercice, \DateTimeImmutable $maintenant, array $synthese, array $pluriannuel, array $activites, array $alertes, array $resultats, array $rapports): array
    {
        $taux = static fn (?float $t): string => null === $t ? '—' : number_format($t * 100, 1, ',', ' ').' %';
        $fcfa = static fn (int $m): string => number_format($m, 0, ',', ' ').' FCFA';

        $debut = $projet->getDateDebut();
        $fin = $projet->getDateFin();
        $duree = max(1, $fin->getTimestamp() - $debut->getTimestamp());
        $ecoule = min(1.0, max(0.0, ($maintenant->getTimestamp() - $debut->getTimestamp()) / $duree));

        $progressions = array_values(array_filter(array_map(static fn (array $x) => $x['activite']['avancement'], $activites), static fn ($p) => null !== $p));
        $terminees = \count(array_filter($activites, static fn (array $x) => 'terminee' === $x['activite']['statut']));
        $enRetard = \count(array_filter($alertes, static fn (array $a) => 'en_retard' === $a['vigilance']));
        $risque = \count($alertes) - $enRetard;

        $avecIntermediaire = array_filter($resultats, static fn (array $r) => null !== $r['atteint']);
        $atteints = \count(array_filter($avecIntermediaire, static fn (array $r) => true === $r['atteint']));
        $sansValeur = \count(array_filter($resultats, static fn (array $r) => !$r['actuel']));
        $enAttente = \count(array_filter($resultats, static fn (array $r) => $r['enAttente']));
        $iod = \count(array_filter($resultats, static fn (array $r) => 'IOD' === $r['type']));

        $recus = \count(array_filter($rapports, static fn (RapportPeriodique $r) => \in_array($r->getStatut(), [StatutRapport::TRANSMIS, StatutRapport::VALIDE], true)));
        $retards = \count(array_filter($rapports, static fn (RapportPeriodique $r) => $r->estEnRetard($maintenant)));

        $global = $pluriannuel['global'];

        return [
            ['PROJET', null, null],
            ['Projet', $projet->getLibelle(), null],
            ['Bailleur', $projet->getBailleur(), null],
            ['Période du projet', $debut->format('d/m/Y').' – '.$fin->format('d/m/Y'), 'Dates enregistrées dans SISEP.'],
            ['Durée écoulée', $taux($ecoule), 'Au '.$maintenant->format('d/m/Y').'.'],
            [null, null, null],
            ['EXÉCUTION FINANCIÈRE — exercice '.$exercice->getAnnee(), null, null],
            ['Budget notifié', $fcfa($synthese['notifie']), null],
            ['Engagé', $fcfa($synthese['engage']), 'Taux d\'engagement : '.$taux($synthese['tauxEngagement'])],
            ['Dépensé', $fcfa($synthese['depense']), 'Taux d\'exécution : '.$taux($synthese['tauxExecution'])],
            ['Décaissé sur engagé', $taux(GrandeursDerivees::taux($synthese['depense'], $synthese['engage'])), 'Part des engagements déjà payée.'],
            ['Cumul des exercices enregistrés', $fcfa((int) $global['depense']).' dépensés', 'Sur '.$fcfa((int) $global['notifie']).' notifiés — '.$taux($global['tauxExecution']).'. Seuls les exercices saisis dans SISEP sont comptés.'],
            [null, null, null],
            ['AVANCEMENT PHYSIQUE', null, null],
            ['Activités du PTBA', (string) \count($activites), $terminees.' terminée(s)'],
            ['Progression moyenne des étapes', $taux([] === $progressions ? null : array_sum($progressions) / \count($progressions) / 100), \count($progressions).' activité(s) suivie(s) en étapes.'],
            ['Activités en alerte', (string) \count($alertes), $enRetard.' en retard, '.$risque.' à risque élevé, au regard du rythme attendu.'],
            [null, null, null],
            ['CADRE DE RÉSULTATS', null, null],
            ['Indicateurs', (string) \count($resultats), $iod.' d\'objectif de développement (IOD), '.(\count($resultats) - $iod).' de résultat intermédiaire (IRI).'],
            ['Objectif intermédiaire atteint', \count($avecIntermediaire) > 0 ? $atteints.' / '.\count($avecIntermediaire) : '—', 'Parmi les indicateurs ayant un objectif intermédiaire et une valeur validée.'],
            ['Sans valeur validée', (string) $sansValeur, null],
            ['Valeurs en attente de validation', (string) $enAttente, null],
            [null, null, null],
            ['REPORTING DES STRUCTURES — exercice '.$exercice->getAnnee(), null, null],
            ['Rapports trimestriels reçus', $recus.' / '.\count($rapports), $retards.' en retard.'],
            [null, null, null],
            ['HORS DE SISEP', null, 'Passation de marchés, sauvegardes environnementales et sociales, décaissements et catégories de dépenses du bailleur : à compléter depuis les outils de l\'UGP.'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $arbre
     *
     * @return list<list<mixed>>
     */
    private function lignesComposantes(array $arbre): array
    {
        $lignes = [];
        $ligne = static function (string $niveau, array $n, array $activites): array {
            $progressions = array_values(array_filter(array_map(static fn (array $a) => $a['avancement'], $activites), static fn ($p) => null !== $p));
            $notifie = (int) $n['notifie'];
            $engage = (int) $n['engage'];
            $depense = (int) $n['depense'];

            return [
                $niveau, $n['code'], $n['libelle'], $notifie, $engage, $depense, GrandeursDerivees::disponible($notifie, $engage),
                TableurExport::taux(GrandeursDerivees::taux($engage, $notifie)), TableurExport::taux(GrandeursDerivees::taux($depense, $notifie)),
                [] === $progressions ? null : round(array_sum($progressions) / \count($progressions) / 100, 4),
                \count($activites), \count(array_filter($activites, static fn (array $a) => 'terminee' === $a['statut'])),
            ];
        };
        foreach ($arbre as $c) {
            $activitesComposante = array_merge(...array_map(static fn (array $sc) => $sc['activites'] ?? [], $c['sousComposantes'] ?? []));
            $lignes[] = $ligne('Composante', $c, $activitesComposante);
            foreach ($c['sousComposantes'] ?? [] as $sc) {
                $lignes[] = $ligne('Sous-composante', $sc, $sc['activites'] ?? []);
            }
        }

        return $lignes;
    }

    /**
     * @param list<RapportPeriodique> $rapports
     *
     * @return list<list<mixed>>
     */
    private function lignesReporting(array $rapports, \DateTimeImmutable $maintenant): array
    {
        $parStructure = [];
        foreach ($rapports as $r) {
            $sigle = $r->getStructure()->getSigle();
            $parStructure[$sigle] ??= [$sigle, 0, 0, 0, null];
            ++$parStructure[$sigle][1];
            if (\in_array($r->getStatut(), [StatutRapport::TRANSMIS, StatutRapport::VALIDE], true)) {
                ++$parStructure[$sigle][2];
            }
            if ($r->estEnRetard($maintenant)) {
                ++$parStructure[$sigle][3];
            }
            $transmission = $r->getDateTransmission();
            if (null !== $transmission && (null === $parStructure[$sigle][4] || $transmission > $parStructure[$sigle][4])) {
                $parStructure[$sigle][4] = $transmission;
            }
        }
        ksort($parStructure);

        return array_values($parStructure);
    }
}
