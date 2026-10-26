<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activite;
use App\Entity\Engagement;
use App\Entity\Exercice;
use App\Entity\SousActivite;
use App\Enum\StatutEcriture;
use App\ValueObject\Montant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Engagement>
 */
class EngagementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Engagement::class);
    }

    /**
     * Somme des engagements déjà validés pour la cible (activité ou
     * sous-activité) sur l'exercice (§ 5.1) — l'engagement en cours de
     * validation est encore en statut brouillon au moment de l'appel, donc
     * naturellement exclu.
     */
    public function sommeValidee(Activite|SousActivite $cible, Exercice $exercice): Montant
    {
        $champ = $cible instanceof Activite ? 'activite' : 'sousActivite';

        $total = $this->createQueryBuilder('e')
            ->select('COALESCE(SUM(e.montant), 0) AS total')
            ->andWhere("e.{$champ} = :cible")
            ->andWhere('e.exercice = :exercice')
            ->andWhere('e.statut = :statut')
            ->setParameter('cible', $cible)
            ->setParameter('exercice', $exercice)
            ->setParameter('statut', StatutEcriture::VALIDE)
            ->getQuery()
            ->getSingleScalarResult();

        return Montant::fromFcfa((int) $total);
    }

    /**
     * @return array{donnees: list<Engagement>, total: int}
     */
    public function filtrer(Activite|SousActivite|null $cible, ?Exercice $exercice, ?StatutEcriture $statut, int $page, int $taille): array
    {
        // Portée par structure (§ 7.2) : le filtre `portee_activite` s'applique
        // aux jointures sur Activite — une activité hors périmètre y devient
        // nulle, et l'engagement qui s'y rattache sort de la liste. Sans ces
        // jointures, un point focal recevait tous les engagements du projet,
        // et l'hydratation de leur activité masquée échouait (500).
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.activite', 'a_portee')
            ->leftJoin('e.sousActivite', 'l_portee')
            ->leftJoin('l_portee.activite', 'la_portee')
            ->andWhere('e.supprimeLe IS NULL')
            ->andWhere('a_portee.id IS NOT NULL OR la_portee.id IS NOT NULL')
            ->orderBy('e.dateEngagement', 'DESC');

        if ($cible instanceof Activite) {
            $qb->andWhere('e.activite = :cible')->setParameter('cible', $cible);
        } elseif ($cible instanceof SousActivite) {
            $qb->andWhere('e.sousActivite = :cible')->setParameter('cible', $cible);
        }
        if (null !== $exercice) {
            $qb->andWhere('e.exercice = :exercice')->setParameter('exercice', $exercice);
        }
        if (null !== $statut) {
            $qb->andWhere('e.statut = :statut')->setParameter('statut', $statut);
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $donnees = $qb->setFirstResult(($page - 1) * $taille)->setMaxResults($taille)->getQuery()->getResult();

        return ['donnees' => $donnees, 'total' => $total];
    }
}
