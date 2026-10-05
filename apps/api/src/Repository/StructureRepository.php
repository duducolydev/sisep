<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Structure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Structure>
 */
class StructureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Structure::class);
    }

    /** @return list<Structure> */
    public function listerActives(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.supprimeLe IS NULL')
            ->andWhere('s.actif = true')
            ->orderBy('s.sigle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vue d'administration (§ 2.6) : y compris les structures désactivées, à réactiver depuis l'écran de gestion.
     *
     * @return list<Structure>
     */
    public function listerNonSupprimees(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.supprimeLe IS NULL')
            ->orderBy('s.sigle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
