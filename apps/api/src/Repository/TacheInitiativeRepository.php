<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InitiativeActivite;
use App\Entity\TacheInitiative;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TacheInitiative>
 */
class TacheInitiativeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TacheInitiative::class);
    }

    /** @return list<TacheInitiative> */
    public function listerNonSupprimees(InitiativeActivite $activite): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.supprimeLe IS NULL')
            ->andWhere('t.initiativeActivite = :activite')
            ->setParameter('activite', $activite)
            ->orderBy('t.ordre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
