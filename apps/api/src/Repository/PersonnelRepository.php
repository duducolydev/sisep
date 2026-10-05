<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Personnel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Personnel>
 */
class PersonnelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Personnel::class);
    }

    /** @return list<Personnel> */
    public function listerNonSupprimes(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.supprimeLe IS NULL')
            ->orderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
