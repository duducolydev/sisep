<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercice;
use App\Entity\RapportPeriodique;
use App\Entity\Structure;
use App\Enum\PeriodeTrimestre;
use App\Enum\StatutRapport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RapportPeriodique>
 */
class RapportPeriodiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RapportPeriodique::class);
    }

    /** @return list<RapportPeriodique> */
    public function filtrer(?Exercice $exercice, ?PeriodeTrimestre $periode, ?StatutRapport $statut): array
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.structure', 's')
            ->addSelect('s')
            ->orderBy('s.sigle', 'ASC');

        if (null !== $exercice) {
            $qb->andWhere('r.exercice = :exercice')->setParameter('exercice', $exercice);
        }
        if (null !== $periode) {
            $qb->andWhere('r.periode = :periode')->setParameter('periode', $periode);
        }
        if (null !== $statut) {
            $qb->andWhere('r.statut = :statut')->setParameter('statut', $statut);
        }

        return $qb->getQuery()->getResult();
    }

    public function trouverPour(Structure $structure, Exercice $exercice, PeriodeTrimestre $periode): ?RapportPeriodique
    {
        return $this->findOneBy(['structure' => $structure, 'exercice' => $exercice, 'periode' => $periode]);
    }
}
