<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Personnel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Personnel>
 */
class PersonnelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Personnel::class);
    }

    /**
     * Rapprochement par identité, pour l'import de l'état du personnel :
     * l'UGP ne gère pas de matricule, le couple prénom + nom est donc la
     * seule clé disponible. La collation MySQL étant insensible à la casse,
     * l'égalité suffit ici.
     */
    public function trouverParIdentite(string $prenom, string $nom): ?Personnel
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.prenom = :prenom')
            ->andWhere('e.nom = :nom')
            ->andWhere('e.supprimeLe IS NULL')
            ->setParameter('prenom', $prenom)
            ->setParameter('nom', $nom)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Personnel> */
    public function listerNonSupprimes(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.supprimeLe IS NULL')
            ->orderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
