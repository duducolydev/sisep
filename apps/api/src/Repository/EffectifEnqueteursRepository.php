<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EffectifEnqueteurs;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EffectifEnqueteurs>
 */
class EffectifEnqueteursRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EffectifEnqueteurs::class);
    }

    /** @return list<EffectifEnqueteurs> */
    public function listerNonSupprimes(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.supprimeLe IS NULL')
            ->orderBy('e.annee', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
