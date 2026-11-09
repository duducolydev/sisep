<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Portée des totaux budgétaires (décision du 09/11/2026) : un utilisateur limité
 * à des structures ne voit les montants que de ses activités, à tous les niveaux
 * d'agrégation. Elle remplace la transparence du § 4.1 point 2 du brief, où les
 * montants agrégés restaient visibles de tous.
 *
 * Sans utilisateur connecté (commandes planifiées), le périmètre est global.
 */
final class PorteeBudgetaire
{
    public function __construct(
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    public function estGlobale(): bool
    {
        $utilisateur = $this->security->getUser();

        return !$utilisateur instanceof Utilisateur
            || $this->permissionsResolver->pour($utilisateur)->estGlobale('activites.consulter');
    }

    /**
     * Fragment SQL à ajouter à une requête portant sur `activites`, précédé de
     * AND ; vide pour une portée globale, jamais vrai pour qui n'a aucune structure.
     *
     * @param string $alias alias SQL de la table `activites`
     *
     * @return array{sql: string, parametres: list<int>}
     */
    public function condition(string $alias = 'a'): array
    {
        if ($this->estGlobale()) {
            return ['sql' => '', 'parametres' => []];
        }

        /** @var Utilisateur $utilisateur estGlobale() a écarté les autres cas */
        $utilisateur = $this->security->getUser();
        $structureIds = $this->permissionsResolver->pour($utilisateur)->structuresAutorisees('activites.consulter');
        if ([] === $structureIds) {
            return ['sql' => ' AND 1 = 0', 'parametres' => []];
        }

        return [
            'sql' => ' AND '.PorteeActiviteSql::condition($alias, implode(',', array_fill(0, \count($structureIds), '?'))),
            'parametres' => [...$structureIds, ...$structureIds],
        ];
    }
}
