<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use App\Entity\Activite;
use App\Security\PorteeActiviteSql;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Portée par structure appliquée en filtre de requête (§ 7.2 du brief) :
 * restreint les lignes visibles, jamais un contrôle après lecture — sinon
 * une liste paginée fuite des lignes que l'utilisateur ne devrait pas
 * compter.
 *
 * Doctrine\ORM\Query\Filter\SQLFilter::setParameter() ne gère que des
 * scalaires, pas une liste — définirPortee() contourne volontairement ce
 * mécanisme et échappe chaque id via Connection::quote() dans
 * addFilterConstraint().
 */
final class PorteeActiviteFilter extends SQLFilter
{
    private bool $actif = false;

    /** @var list<int> */
    private array $structureIds = [];

    /** @param list<int> $structureIds */
    public function definirPortee(array $structureIds): void
    {
        $this->actif = true;
        $this->structureIds = $structureIds;

        // Doctrine met en cache le SQL généré (query cache, persistant en
        // production) sous une clé qui inclut l'empreinte des filtres — et
        // cette empreinte ne voit que les paramètres déclarés. Sans celui-ci,
        // tous les comptes à portée partageaient la même clé : le SQL d'un
        // point focal, ses ids de structures inscrits en dur, resservait au
        // suivant (audit du 26/10/2026).
        $ids = $structureIds;
        sort($ids);
        $this->setParameter('structures', implode(',', $ids));
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (Activite::class !== $targetEntity->getName() || !$this->actif) {
            return '';
        }

        if ([] === $this->structureIds) {
            return '1 = 0';
        }

        $idsEchappes = array_map(
            fn (int $id): string => $this->getConnection()->quote((string) $id),
            $this->structureIds,
        );

        // Responsable de l'activité ou d'une de ses lignes : la même règle
        // que les lecteurs SQL, cf. PorteeActiviteSql.
        return PorteeActiviteSql::condition($targetTableAlias, implode(',', $idsEchappes));
    }
}
