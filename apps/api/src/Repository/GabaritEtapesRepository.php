<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GabaritEtapes;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GabaritEtapes>
 */
class GabaritEtapesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GabaritEtapes::class);
    }

    /** @return list<GabaritEtapes> catalogue proposé à l'instanciation : les gabarits retirés en sont exclus. */
    public function listerActifs(): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.supprimeLe IS NULL')
            ->andWhere('g.actif = true')
            ->orderBy('g.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<GabaritEtapes> */
    public function listerNonSupprimes(): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.supprimeLe IS NULL')
            ->orderBy('g.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
