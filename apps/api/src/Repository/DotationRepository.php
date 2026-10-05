<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activite;
use App\Entity\Dotation;
use App\Entity\Exercice;
use App\Entity\SousActivite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Dotation>
 */
class DotationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dotation::class);
    }

    public function trouverPourCibleExercice(Activite|SousActivite $cible, Exercice $exercice): ?Dotation
    {
        return $cible instanceof Activite
            ? $this->findOneBy(['activite' => $cible, 'exercice' => $exercice])
            : $this->findOneBy(['sousActivite' => $cible, 'exercice' => $exercice]);
    }
}
