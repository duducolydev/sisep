<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Composante;
use App\Entity\SousComposante;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SousComposante>
 */
class SousComposanteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SousComposante::class);
    }

    /** @return list<SousComposante> */
    public function listerNonSupprimees(?Composante $composante = null): array
    {
        $qb = $this->createQueryBuilder('sc')
            ->andWhere('sc.supprimeLe IS NULL')
            ->orderBy('sc.ordre', 'ASC');

        if (null !== $composante) {
            $qb->andWhere('sc.composante = :composante')->setParameter('composante', $composante);
        }

        return $qb->getQuery()->getResult();
    }
}
