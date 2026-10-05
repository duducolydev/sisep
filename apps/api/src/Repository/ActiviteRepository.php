<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activite>
 */
class ActiviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activite::class);
    }

    /**
     * Le filtre SQL de portée (§ 7.2) s'applique automatiquement ici : cette
     * méthode ne connaît rien de l'utilisateur courant, la restriction est
     * injectée par PorteeStructureRequestListener au niveau de la connexion.
     *
     * @return array{donnees: list<Activite>, total: int}
     */
    public function paginer(int $page, int $taille): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.supprimeLe IS NULL')
            ->orderBy('a.code', 'ASC');

        $total = (int) (clone $qb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $donnees = $qb
            ->setFirstResult(($page - 1) * $taille)
            ->setMaxResults($taille)
            ->getQuery()
            ->getResult();

        return ['donnees' => $donnees, 'total' => $total];
    }
}
