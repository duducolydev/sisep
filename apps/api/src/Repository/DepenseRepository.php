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

    /**
     * Dépenses en brouillon, les plus anciennes d'abord — l'écran « À
     * valider » du budget. La portée par structure suit l'activité de
     * l'engagement (filtre `portee_activite` sur les jointures).
     *
     * @return list<Depense>
     */
    public function brouillons(int $limite): array
    {
        /** @var list<Depense> $resultat */
        $resultat = $this->createQueryBuilder('d')
            ->join('d.engagement', 'e')
            ->leftJoin('e.activite', 'a_portee')
            ->leftJoin('e.sousActivite', 'l_portee')
            ->leftJoin('l_portee.activite', 'la_portee')
            ->andWhere('d.supprimeLe IS NULL')
            ->andWhere('d.statut = :statut')
            ->andWhere('a_portee.id IS NOT NULL OR la_portee.id IS NOT NULL')
            ->setParameter('statut', StatutEcriture::BROUILLON)
            ->orderBy('d.dateDepense', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return $resultat;
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
