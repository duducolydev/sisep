<?php

declare(strict_types=1);

namespace App\Query;

use App\Budget\GrandeursDerivees;
use Doctrine\DBAL\Connection;

/**
 * GET /projets/:uuid/synthese?exercice= (§ 2.1, § 6.2 du brief) : les quatre
 * cartes du tableau de bord — notifié, engagé, exécuté, disponible —, somme
 * projet entier sans filtre de portée (comme les totaux composante de
 * l'arbre, § 4.1 point 2 : les montants agrégés restent visibles).
 */
final class SyntheseReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{notifie: int, engage: int, depense: int, disponible: ?int, tauxEngagement: ?float, tauxExecution: ?float} */
    public function lire(int $projetId, int $exerciceId): array
    {
        $ligne = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(v.notifie), 0) AS notifie,
                    COALESCE(SUM(v.engage), 0) AS engage,
                    COALESCE(SUM(v.depense), 0) AS depense
             FROM composantes c
             JOIN v_execution_composante v ON v.composante_id = c.id AND v.exercice_id = ?
             WHERE c.projet_id = ? AND c.supprime_le IS NULL',
            [$exerciceId, $projetId],
        );

        $notifie = (int) ($ligne['notifie'] ?? 0);
        $engage = (int) ($ligne['engage'] ?? 0);
        $depense = (int) ($ligne['depense'] ?? 0);

        return [
            'notifie' => $notifie,
            'engage' => $engage,
            'depense' => $depense,
            'disponible' => GrandeursDerivees::disponible($notifie, $engage),
            'tauxEngagement' => GrandeursDerivees::taux($engage, $notifie),
            'tauxExecution' => GrandeursDerivees::taux($depense, $notifie),
        ];
    }
}
