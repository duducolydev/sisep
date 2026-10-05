<?php

declare(strict_types=1);

namespace App\Query;

use App\Budget\GrandeursDerivees;
use App\Entity\Exercice;
use App\Entity\Utilisateur;
use App\Enum\StatutActivite;
use App\Enum\Vigilance;
use App\Security\PermissionsResolver;
use App\Vigilance\VigilanceCalculator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /projets/:uuid/alertes (§ 2.1, § 5.3 du brief) : activités en retard ou
 * à risque élevé — jamais stockées, recalculées à chaque appel à partir des
 * vues d'exécution et des seuils courants (§ 5.3, critère d'acceptation P5).
 * Même filtre de portée que BudgetExecutionReader::lireActivites — un point
 * focal ne voit d'alertes que sur les activités de sa structure.
 */
final class AlertesReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly VigilanceCalculator $vigilanceCalculator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function lire(int $projetId, Exercice $exercice): array
    {
        $utilisateur = $this->security->getUser();
        $permissions = $utilisateur instanceof Utilisateur ? $this->permissionsResolver->pour($utilisateur) : null;

        $conditionPortee = '';
        $parametresPortee = [];
        if (null !== $permissions && !$permissions->estGlobale('activites.consulter')) {
            $structureIds = $permissions->structuresAutorisees('activites.consulter');
            if ([] === $structureIds) {
                return [];
            }
            $conditionPortee = ' AND a.structure_responsable_id IN ('.implode(',', array_fill(0, \count($structureIds), '?')).')';
            $parametresPortee = $structureIds;
        }

        $sql = 'SELECT a.uuid, a.code, a.libelle, a.date_fin_prevue, a.statut,
                       s.sigle AS structure_sigle, v.notifie, v.engage, v.depense
                FROM activites a
                JOIN sous_composantes sc ON sc.id = a.sous_composante_id
                JOIN composantes c ON c.id = sc.composante_id
                JOIN structures s ON s.id = a.structure_responsable_id
                LEFT JOIN v_execution_activite v ON v.activite_id = a.id AND v.exercice_id = ?
                WHERE c.projet_id = ? AND a.supprime_le IS NULL'
            .$conditionPortee
            .' ORDER BY a.code';

        $params = [(int) $exercice->getId(), $projetId, ...$parametresPortee];
        $lignes = $this->connection->fetchAllAssociative($sql, $params);

        $aujourdhui = new \DateTimeImmutable('today');
        $tauxAttendu = $exercice->tauxAttendu($aujourdhui);

        $alertes = [];
        foreach ($lignes as $ligne) {
            $notifie = null !== $ligne['notifie'] ? (int) $ligne['notifie'] : null;
            $depense = null !== $ligne['depense'] ? (int) $ligne['depense'] : null;
            $tauxExecution = GrandeursDerivees::taux($depense, $notifie);

            $dateFinPrevue = null !== $ligne['date_fin_prevue'] ? new \DateTimeImmutable($ligne['date_fin_prevue']) : null;
            $statut = StatutActivite::from($ligne['statut']);
            $echeanceDepassee = null !== $dateFinPrevue && $dateFinPrevue < $aujourdhui;

            $vigilance = $this->vigilanceCalculator->calculer(
                $tauxExecution,
                $tauxAttendu,
                $echeanceDepassee,
                StatutActivite::TERMINEE === $statut,
            );

            if (Vigilance::NORMAL === $vigilance) {
                continue;
            }

            $alertes[] = [
                'uuid' => $ligne['uuid'],
                'code' => $ligne['code'],
                'libelle' => $ligne['libelle'],
                'structure' => $ligne['structure_sigle'],
                'statut' => $statut->value,
                'vigilance' => $vigilance->value,
                'tauxExecution' => $tauxExecution,
                'tauxAttendu' => $tauxAttendu,
                'dateFinPrevue' => $dateFinPrevue?->format('Y-m-d'),
            ];
        }

        return $alertes;
    }
}
