<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contrat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contrat>
 */
class ContratRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contrat::class);
    }

    /**
     * Contrats dont l'échéance tombe d'ici la date limite, les plus urgents
     * d'abord — source des alertes de fin de contrat (§ 10 du CR du 22/09/2026)
     * et de l'encart d'échéances du tableau de bord RH. Les contrats sans
     * terme fixé en sont exclus : il n'y a rien à y surveiller.
     *
     * @return list<Contrat>
     */
    public function echeantsAvant(\DateTimeImmutable $limite): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.supprimeLe IS NULL')
            ->andWhere('e.dateFin IS NOT NULL')
            ->andWhere('e.dateFin <= :limite')
            ->setParameter('limite', $limite)
            ->orderBy('e.dateFin', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Contrat> */
    public function listerNonSupprimes(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.supprimeLe IS NULL')
            ->orderBy('e.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
