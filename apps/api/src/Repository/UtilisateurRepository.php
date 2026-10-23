<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PointFocal;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /** @return list<Utilisateur> */
    public function listerNonSupprimes(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.supprimeLe IS NULL')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Utilisateur> porteurs (toute portée confondue) du rôle donné — sert à lister les sectoriels éligibles à une invitation d'indicateur. */
    public function listerParRole(string $codeRole): array
    {
        return $this->createQueryBuilder('u')
            ->innerJoin('u.attributionsRoles', 'attribution')
            ->innerJoin('attribution.role', 'role')
            ->andWhere('role.code = :code')
            ->andWhere('u.supprimeLe IS NULL')
            ->setParameter('code', $codeRole)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Comptes rattachés à une fiche de point focal active (« Lier un
     * compte ») — un point focal désigné par sa structure est un sectoriel,
     * même si son compte ne porte pas encore le rôle correspondant.
     *
     * @return list<Utilisateur>
     */
    public function listerLiesAUnPointFocal(): array
    {
        return $this->createQueryBuilder('u')
            ->innerJoin(PointFocal::class, 'pf', 'WITH', 'pf.utilisateur = u')
            ->andWhere('pf.actif = true')
            ->andWhere('u.supprimeLe IS NULL')
            ->distinct()
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
