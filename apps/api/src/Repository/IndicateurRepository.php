<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Composante;
use App\Entity\Indicateur;
use App\Entity\Projet;
use App\Entity\Structure;
use App\Enum\TypeIndicateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Indicateur>
 */
class IndicateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Indicateur::class);
    }

    /** @return list<Indicateur> */
    public function filtrer(?Projet $projet, ?TypeIndicateur $type, ?Composante $composante, ?Structure $structureResponsable = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.supprimeLe IS NULL')
            ->orderBy('i.code', 'ASC');

        if (null !== $projet) {
            $qb->andWhere('i.projet = :projet')->setParameter('projet', $projet);
        }
        if (null !== $type) {
            $qb->andWhere('i.type = :type')->setParameter('type', $type);
        }
        if (null !== $composante) {
            $qb->andWhere('i.composante = :composante')->setParameter('composante', $composante);
        }
        if (null !== $structureResponsable) {
            $qb->andWhere('i.structureResponsable = :structureResponsable')->setParameter('structureResponsable', $structureResponsable);
        }

        return $qb->getQuery()->getResult();
    }
}
