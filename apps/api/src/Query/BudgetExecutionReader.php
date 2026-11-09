<?php

declare(strict_types=1);

namespace App\Query;

use App\Budget\GrandeursDerivees;
use App\Entity\Utilisateur;
use App\Security\PermissionsResolver;
use App\Security\PorteeActiviteSql;
use App\Security\PorteeBudgetaire;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /budget/execution?exercice=&composante=&niveau= (§ 6.2 du brief).
 * Comme ArbreReader, DBAL direct plutôt que l'hydratation ORM (§ 4.7) : pure
 * agrégation en lecture contre les vues v_execution_*.
 */
final class BudgetExecutionReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly PorteeBudgetaire $portee,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function lire(int $projetId, int $exerciceId, string $niveau, ?int $composanteId): array
    {
        return match ($niveau) {
            'composante' => $this->lireComposantes($projetId, $exerciceId, $composanteId),
            'sous_composante' => $this->lireSousComposantes($projetId, $exerciceId, $composanteId),
            'activite' => $this->lireActivites($projetId, $exerciceId, $composanteId),
            default => throw new \InvalidArgumentException("Niveau inconnu : {$niveau}"),
        };
    }

    /** @return list<array<string, mixed>> */
    private function lireComposantes(int $projetId, int $exerciceId, ?int $composanteId): array
    {
        // Sommé depuis les activités, et non lu dans v_execution_composante,
        // qui ignore la portée : la composante reste listée pour situer ses
        // activités, ses montants ne portent que sur le périmètre de l'utilisateur.
        $portee = $this->portee->condition();
        $sql = 'SELECT c.uuid, c.code, c.libelle, SUM(v.notifie) AS notifie, SUM(v.engage) AS engage, SUM(v.depense) AS depense
                FROM composantes c
                LEFT JOIN sous_composantes sc ON sc.composante_id = c.id AND sc.supprime_le IS NULL
                LEFT JOIN activites a ON a.sous_composante_id = sc.id AND a.supprime_le IS NULL'.$portee['sql'].'
                LEFT JOIN v_execution_activite v ON v.activite_id = a.id AND v.exercice_id = ?
                WHERE c.projet_id = ? AND c.supprime_le IS NULL'
            .(null !== $composanteId ? ' AND c.id = ?' : '')
            .' GROUP BY c.id, c.uuid, c.code, c.libelle, c.ordre ORDER BY c.ordre, c.code';

        $params = [...$portee['parametres'], $exerciceId, $projetId];
        if (null !== $composanteId) {
            $params[] = $composanteId;
        }

        return array_map($this->normaliserLigne(...), $this->connection->fetchAllAssociative($sql, $params));
    }

    /** @return list<array<string, mixed>> */
    private function lireSousComposantes(int $projetId, int $exerciceId, ?int $composanteId): array
    {
        $portee = $this->portee->condition();
        $sql = 'SELECT sc.uuid, sc.code, sc.libelle, SUM(v.notifie) AS notifie, SUM(v.engage) AS engage, SUM(v.depense) AS depense
                FROM sous_composantes sc
                JOIN composantes c ON c.id = sc.composante_id
                LEFT JOIN activites a ON a.sous_composante_id = sc.id AND a.supprime_le IS NULL'.$portee['sql'].'
                LEFT JOIN v_execution_activite v ON v.activite_id = a.id AND v.exercice_id = ?
                WHERE c.projet_id = ? AND sc.supprime_le IS NULL'
            .(null !== $composanteId ? ' AND c.id = ?' : '')
            .' GROUP BY sc.id, sc.uuid, sc.code, sc.libelle, sc.ordre ORDER BY sc.ordre, sc.code';

        $params = [...$portee['parametres'], $exerciceId, $projetId];
        if (null !== $composanteId) {
            $params[] = $composanteId;
        }

        return array_map($this->normaliserLigne(...), $this->connection->fetchAllAssociative($sql, $params));
    }

    /** @return list<array<string, mixed>> */
    private function lireActivites(int $projetId, int $exerciceId, ?int $composanteId): array
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
            $conditionPortee = ' AND '.PorteeActiviteSql::condition('a', implode(',', array_fill(0, \count($structureIds), '?')));
            $parametresPortee = [...$structureIds, ...$structureIds];
        }

        $sql = 'SELECT a.uuid, a.code, a.libelle, v.notifie, v.engage, v.depense
                FROM activites a
                JOIN sous_composantes sc ON sc.id = a.sous_composante_id
                JOIN composantes c ON c.id = sc.composante_id
                LEFT JOIN v_execution_activite v ON v.activite_id = a.id AND v.exercice_id = ?
                WHERE c.projet_id = ? AND a.supprime_le IS NULL'
            .(null !== $composanteId ? ' AND c.id = ?' : '')
            .$conditionPortee
            .' ORDER BY a.code';

        $params = [$exerciceId, $projetId];
        if (null !== $composanteId) {
            $params[] = $composanteId;
        }
        $params = [...$params, ...$parametresPortee];

        return array_map($this->normaliserLigne(...), $this->connection->fetchAllAssociative($sql, $params));
    }

    /**
     * @param array<string, mixed> $ligne
     *
     * @return array<string, mixed>
     */
    private function normaliserLigne(array $ligne): array
    {
        $notifie = null !== $ligne['notifie'] ? (int) $ligne['notifie'] : null;
        $engage = null !== $ligne['engage'] ? (int) $ligne['engage'] : null;
        $depense = null !== $ligne['depense'] ? (int) $ligne['depense'] : null;

        return [
            'uuid' => $ligne['uuid'],
            'code' => $ligne['code'],
            'libelle' => $ligne['libelle'],
            'notifie' => $notifie,
            'engage' => $engage,
            'depense' => $depense,
            'disponible' => GrandeursDerivees::disponible($notifie, $engage),
            'tauxEngagement' => GrandeursDerivees::taux($engage, $notifie),
            'tauxExecution' => GrandeursDerivees::taux($depense, $notifie),
            'tauxConsommation' => GrandeursDerivees::taux($depense, $engage),
        ];
    }
}
