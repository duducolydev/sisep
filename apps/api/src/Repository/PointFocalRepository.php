<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PointFocal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PointFocal>
 */
class PointFocalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PointFocal::class);
    }
}
