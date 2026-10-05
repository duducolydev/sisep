<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Initiative;
use App\Entity\InitiativeActivite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InitiativeActivite>
 */
class InitiativeActiviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InitiativeActivite::class);
    }

    /** @return list<InitiativeActivite> */
    public function listerNonSupprimees(Initiative $initiative): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.supprimeLe IS NULL')
            ->andWhere('a.initiative = :initiative')
            ->setParameter('initiative', $initiative)
            ->orderBy('a.ordre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
