<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\Connection;

/**
 * GET /projets/:uuid/execution-pluriannuelle : contrairement à
 * DecaissementReader (une série mensuelle au sein d'un seul exercice), ce
 * reader agrège tout le projet, tous exercices confondus — un total par
 * année (dotations notifiées vs dépenses validées, tout le projet), plus un
 * cumul global. "Objectif annuel" = somme des dotations notifiées sur
 * l'exercice ; "Exécution" = somme des dépenses validées sur l'exercice
 * (même filtre `statut = 'valide'` que partout ailleurs).
 */
final class ExecutionPluriannuelleReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{annees: list<array{annee: int, notifie: int, depense: int, disponible: int, tauxExecution: float|null}>, global: array{notifie: int, depense: int, disponible: int, tauxExecution: float|null}} */
    public function lire(int $projetId): array
    {
        // La dotation/dépense peut cibler l'activité elle-même ou une de ses
        // sous-activités (§ 6.2, alignement PTBA) : résolution par COALESCE,
        // même logique que DecaissementReader.
        $notifieParAnnee = $this->connection->fetchAllKeyValue(
            'SELECT e.annee, SUM(d.montant) AS total
             FROM dotations d
             JOIN exercices e ON e.id = d.exercice_id
             LEFT JOIN sous_activites sa ON sa.id = d.sous_activite_id
             JOIN activites a ON a.id = COALESCE(d.activite_id, sa.activite_id)
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id
             JOIN composantes c ON c.id = sc.composante_id
             WHERE c.projet_id = ?
             GROUP BY e.annee',
            [$projetId],
        );

        $depenseParAnnee = $this->connection->fetchAllKeyValue(
            "SELECT ex.annee, SUM(d.montant) AS total
             FROM depenses d
             JOIN engagements e ON e.id = d.engagement_id
             JOIN exercices ex ON ex.id = e.exercice_id
             LEFT JOIN sous_activites sa ON sa.id = e.sous_activite_id
             JOIN activites a ON a.id = COALESCE(e.activite_id, sa.activite_id)
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id
             JOIN composantes c ON c.id = sc.composante_id
             WHERE c.projet_id = ? AND d.statut = 'valide'
             GROUP BY ex.annee",
            [$projetId],
        );

        $annees = array_unique([...array_map('intval', array_keys($notifieParAnnee)), ...array_map('intval', array_keys($depenseParAnnee))]);
        sort($annees);

        $lignes = [];
        $notifieGlobal = 0;
        $depenseGlobal = 0;
        foreach ($annees as $annee) {
            $notifie = (int) ($notifieParAnnee[$annee] ?? 0);
            $depense = (int) ($depenseParAnnee[$annee] ?? 0);
            $lignes[] = $this->ligne($annee, $notifie, $depense);
            $notifieGlobal += $notifie;
            $depenseGlobal += $depense;
        }

        return [
            'annees' => $lignes,
            'global' => $this->ligne(null, $notifieGlobal, $depenseGlobal),
        ];
    }

    /** @return array{annee?: int, notifie: int, depense: int, disponible: int, tauxExecution: float|null} */
    private function ligne(?int $annee, int $notifie, int $depense): array
    {
        $ligne = [
            'notifie' => $notifie,
            'depense' => $depense,
            'disponible' => $notifie - $depense,
            // Cast explicite : PHP renvoie un int (pas un float) quand la
            // division tombe juste (ex. 0 / 5 000 000), ce qui romprait le
            // contrat float|null côté API.
            'tauxExecution' => $notifie > 0 ? (float) $depense / $notifie : null,
        ];

        if (null !== $annee) {
            return ['annee' => $annee, ...$ligne];
        }

        return $ligne;
    }
}
