<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EtapeGabarit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EtapeGabarit>
 */
class EtapeGabaritRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EtapeGabarit::class);
    }
}
