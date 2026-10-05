<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Depense;
use App\Entity\Engagement;
use App\Enum\StatutEcriture;
use App\ValueObject\Montant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Depense>
 */
class DepenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Depense::class);
    }

    /** Somme des dépenses déjà validées pour l'engagement (§ 5.1). */
    public function sommeValidee(Engagement $engagement): Montant
    {
        $total = $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.montant), 0) AS total')
            ->andWhere('d.engagement = :engagement')
            ->andWhere('d.statut = :statut')
            ->setParameter('engagement', $engagement)
            ->setParameter('statut', StatutEcriture::VALIDE)
            ->getQuery()
            ->getSingleScalarResult();

        return Montant::fromFcfa((int) $total);
    }
}
