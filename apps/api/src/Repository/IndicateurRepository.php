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

    /**
     * L'index unique (projet_id, code, supprime_le) ne protège pas les
     * indicateurs actifs : supprime_le y vaut NULL, et MySQL ne compare
     * jamais deux NULL entre eux. Le contrôle se fait donc ici, sur les seuls
     * indicateurs non supprimés — un code libéré par une suppression peut
     * resservir.
     */
    public function codeDejaUtilise(Projet $projet, string $code, ?Indicateur $saufCelui = null): bool
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.projet = :projet')->setParameter('projet', $projet)
            ->andWhere('i.code = :code')->setParameter('code', $code)
            ->andWhere('i.supprimeLe IS NULL');
        if (null !== $saufCelui) {
            $qb->andWhere('i != :saufCelui')->setParameter('saufCelui', $saufCelui);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** Un indicateur supprimé (logiquement) n'est plus consultable ni modifiable. */
    public function trouverNonSupprime(\Symfony\Component\Uid\Uuid $uuid): ?Indicateur
    {
        return $this->findOneBy(['uuid' => $uuid, 'supprimeLe' => null]);
    }

    /**
     * @param bool $objectifGeneral indicateurs sans composante seulement — les
     *                              indicateurs transversaux de l'objectif général
     *
     * @return list<Indicateur>
     */
    public function filtrer(?Projet $projet, ?TypeIndicateur $type, ?Composante $composante, ?Structure $structureResponsable = null, bool $objectifGeneral = false): array
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
        } elseif ($objectifGeneral) {
            $qb->andWhere('i.composante IS NULL');
        }
        if (null !== $structureResponsable) {
            $qb->andWhere('i.structureResponsable = :structureResponsable')->setParameter('structureResponsable', $structureResponsable);
        }

        return $qb->getQuery()->getResult();
    }
}
