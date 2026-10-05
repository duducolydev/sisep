<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\Connection;

/**
 * GET /projets/:uuid/decaissement?exercice= (§ 6.2 du brief) : série
 * mensuelle cumulée réalisée comparée à une trajectoire attendue linéaire
 * sur l'exercice (même logique que le taux attendu du § 5.3 — jours écoulés
 * / jours totaux — appliquée mois par mois plutôt qu'à la date du jour).
 */
final class DecaissementReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array{mois: string, realise: int, prevu: int}> */
    public function lire(int $projetId, int $exerciceId, \DateTimeImmutable $dateDebut, \DateTimeImmutable $dateFin): array
    {
        // La dotation/l'engagement peut cibler l'activité elle-même ou une de
        // ses sous-activités (§ 6.2, alignement PTBA PHASAOC) : on résout
        // dans tous les cas l'activité porteuse via COALESCE pour ne pas
        // silencieusement perdre les lignes chiffrées au niveau sous-activité.
        $totalNotifie = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(d.montant), 0)
             FROM dotations d
             LEFT JOIN sous_activites sa ON sa.id = d.sous_activite_id
             JOIN activites a ON a.id = COALESCE(d.activite_id, sa.activite_id)
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id
             JOIN composantes c ON c.id = sc.composante_id
             WHERE c.projet_id = ? AND d.exercice_id = ?',
            [$projetId, $exerciceId],
        );

        $depensesParMois = $this->connection->fetchAllKeyValue(
            "SELECT DATE_FORMAT(d.date_depense, '%Y-%m') AS mois, SUM(d.montant) AS total
             FROM depenses d
             JOIN engagements e ON e.id = d.engagement_id
             LEFT JOIN sous_activites sa ON sa.id = e.sous_activite_id
             JOIN activites a ON a.id = COALESCE(e.activite_id, sa.activite_id)
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id
             JOIN composantes c ON c.id = sc.composante_id
             WHERE c.projet_id = ? AND e.exercice_id = ? AND d.statut = 'valide'
             GROUP BY mois",
            [$projetId, $exerciceId],
        );

        $joursTotaux = max(1, $dateDebut->diff($dateFin)->days);

        $points = [];
        $cumulRealise = 0;
        $curseur = $dateDebut->modify('first day of this month');

        while ($curseur <= $dateFin) {
            $cle = $curseur->format('Y-m');
            $cumulRealise += (int) ($depensesParMois[$cle] ?? 0);

            $finMoisBornee = min($curseur->modify('last day of this month'), $dateFin);
            $joursEcoules = min($joursTotaux, max(0, $dateDebut->diff($finMoisBornee)->days + 1));
            $prevu = $totalNotifie > 0 ? (int) round($totalNotifie * $joursEcoules / $joursTotaux) : 0;

            $points[] = ['mois' => $cle, 'realise' => $cumulRealise, 'prevu' => $prevu];

            $curseur = $curseur->modify('first day of next month');
        }

        return $points;
    }
}
