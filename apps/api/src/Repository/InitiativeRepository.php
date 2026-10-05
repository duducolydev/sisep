<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Initiative;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Initiative>
 */
class InitiativeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Initiative::class);
    }

    /** @return list<Initiative> */
    public function listerNonSupprimees(): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.supprimeLe IS NULL')
            ->orderBy('i.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
