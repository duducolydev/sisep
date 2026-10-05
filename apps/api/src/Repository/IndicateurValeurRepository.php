<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Indicateur;
use App\Entity\IndicateurValeur;
use App\Enum\StatutValeurIndicateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicateurValeur>
 */
class IndicateurValeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicateurValeur::class);
    }

    /** @return list<IndicateurValeur> du plus ancien au plus récent */
    public function lister(Indicateur $indicateur): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.indicateur = :indicateur')
            ->setParameter('indicateur', $indicateur)
            ->orderBy('v.periode', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * "Valeur actuelle" (§ 2.4, § 5.2) : la dernière période validée, pas la
     * dernière saisie — même logique que le budget, qui n'agrège que le
     * validé (§ 4.7).
     */
    public function derniereValidee(Indicateur $indicateur): ?IndicateurValeur
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.indicateur = :indicateur')
            ->andWhere('v.statut = :statut')
            ->setParameter('indicateur', $indicateur)
            ->setParameter('statut', StatutValeurIndicateur::VALIDE)
            ->orderBy('v.periode', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Base d'un ajustement incrémental (§ décision) : la dernière saisie
     * avant la période visée, validée ou non — contrairement à
     * derniereValidee(), qui n'agrège que le validé pour l'« actuel »
     * affiché. Deux logiques distinctes, toutes deux légitimes.
     */
    public function derniere(Indicateur $indicateur, ?\DateTimeImmutable $avant = null): ?IndicateurValeur
    {
        $qb = $this->createQueryBuilder('v')
            ->andWhere('v.indicateur = :indicateur')
            ->setParameter('indicateur', $indicateur)
            ->orderBy('v.periode', 'DESC')
            ->setMaxResults(1);

        if (null !== $avant) {
            $qb->andWhere('v.periode < :avant')->setParameter('avant', $avant);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** Égalité exacte de période — sert à détecter une resaisie (upsert) sur une période déjà renseignée. */
    public function trouverPourPeriode(Indicateur $indicateur, \DateTimeImmutable $periode): ?IndicateurValeur
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.indicateur = :indicateur')
            ->andWhere('v.periode = :periode')
            ->setParameter('indicateur', $indicateur)
            ->setParameter('periode', $periode)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
