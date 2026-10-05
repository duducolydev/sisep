<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Composante;
use App\Entity\Projet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Composante>
 */
class ComposanteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Composante::class);
    }

    /** @return list<Composante> */
    public function listerNonSupprimees(?Projet $projet = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.supprimeLe IS NULL')
            ->orderBy('c.ordre', 'ASC');

        if (null !== $projet) {
            $qb->andWhere('c.projet = :projet')->setParameter('projet', $projet);
        }

        return $qb->getQuery()->getResult();
    }
}
