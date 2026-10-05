<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Parametre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Parametre>
 */
class ParametreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Parametre::class);
    }

    /**
     * Défensif : si le seed n'a pas (encore) posé ce paramètre, on retombe
     * sur la valeur par défaut plutôt que de faire planter le calcul de
     * vigilance (§ 5.3 — "les seuils sont des paramètres, pas des constantes").
     */
    public function valeurFlottante(string $code, float $defaut): float
    {
        $parametre = $this->findOneBy(['code' => $code]);

        return null !== $parametre ? (float) $parametre->getValeur() : $defaut;
    }

    /** Même défense que valeurFlottante() — utilisé pour rapport.echeance_jours (§ 5.5 du brief). */
    public function valeurEntiere(string $code, int $defaut): int
    {
        $parametre = $this->findOneBy(['code' => $code]);

        return null !== $parametre ? (int) $parametre->getValeur() : $defaut;
    }

    /** @return list<Parametre> */
    public function listerToutes(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
