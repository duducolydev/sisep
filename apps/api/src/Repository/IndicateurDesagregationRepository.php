<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndicateurDesagregation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicateurDesagregation>
 */
class IndicateurDesagregationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicateurDesagregation::class);
    }
}
