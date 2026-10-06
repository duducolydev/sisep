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

    /**
     * Exercice à afficher quand l'utilisateur n'en demande aucun : l'année en
     * cours, et le plus récent seulement si elle n'existe pas.
     *
     * Le repli précédent — toujours le plus récent — ouvrait l'application
     * sur la dernière année du projet (2028), vide de toute écriture : le
     * budget, l'arbre et les exports affichaient zéro tant qu'on n'avait pas
     * choisi une année à la main.
     */
    public function courantOuPlusRecent(Projet $projet): ?Exercice
    {
        return $this->trouverParAnnee($projet, (int) date('Y'))
            ?? $this->findOneBy(['projet' => $projet], ['annee' => 'DESC']);
    }

    /** @return list<Exercice> */
    public function lister(Projet $projet): array
    {
        return $this->findBy(['projet' => $projet], ['annee' => 'DESC']);
    }
}
