<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercice;
use App\Entity\Projet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Exercice>
 */
class ExerciceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exercice::class);
    }

    public function trouverParAnnee(Projet $projet, int $annee): ?Exercice
    {
        return $this->findOneBy(['projet' => $projet, 'annee' => $annee]);
    }

    /** @return list<Exercice> */
    public function lister(Projet $projet): array
    {
        return $this->findBy(['projet' => $projet], ['annee' => 'DESC']);
    }
}
