<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PieceJointe;
use App\Enum\FamilleDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PieceJointe>
 */
class PieceJointeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PieceJointe::class);
    }

    /**
     * Documents d'une entité, filtrés pour la bibliothèque : recherche
     * libre, fenêtre de dépôt, famille de fichier.
     *
     * La famille se traduit en fragments de type MIME plutôt qu'en colonne :
     * c'est une grandeur dérivée, et la stocker obligerait à la recalculer
     * sur tout l'existant au moindre ajustement de la classification.
     *
     * @return list<PieceJointe>
     */
    public function filtrer(
        string $entiteType,
        int $entiteId,
        ?string $recherche = null,
        ?\DateTimeImmutable $du = null,
        ?\DateTimeImmutable $au = null,
        ?FamilleDocument $famille = null,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.entiteType = :type')
            ->andWhere('p.entiteId = :id')
            ->setParameter('type', $entiteType)
            ->setParameter('id', $entiteId)
            ->orderBy('p.creeLe', 'DESC');

        if (null !== $recherche) {
            // L'intitulé comme le nom de fichier : on cherche un document
            // par ce qu'il est, et parfois par le nom sous lequel on l'a
            // reçu.
            $qb->andWhere('LOWER(p.libelle) LIKE :recherche OR LOWER(p.nomFichier) LIKE :recherche')
                ->setParameter('recherche', '%'.mb_strtolower($recherche).'%');
        }
        if (null !== $du) {
            $qb->andWhere('p.creeLe >= :du')->setParameter('du', $du);
        }
        if (null !== $au) {
            $qb->andWhere('p.creeLe <= :au')->setParameter('au', $au);
        }

        if (null !== $famille) {
            $fragments = $famille->fragmentsMime();
            if ([] === $fragments) {
                // « Autre » se définit par la négative : tout ce qu'aucune
                // autre famille ne reconnaît.
                foreach (FamilleDocument::cases() as $index => $cas) {
                    foreach ($cas->fragmentsMime() as $rang => $fragment) {
                        $cle = \sprintf('hors%d_%d', $index, $rang);
                        $qb->andWhere(\sprintf('LOWER(p.mime) NOT LIKE :%s', $cle))->setParameter($cle, '%'.$fragment.'%');
                    }
                }
            } else {
                $conditions = [];
                foreach ($fragments as $rang => $fragment) {
                    $cle = 'mime'.$rang;
                    $conditions[] = \sprintf('LOWER(p.mime) LIKE :%s', $cle);
                    $qb->setParameter($cle, '%'.$fragment.'%');
                }
                $qb->andWhere(implode(' OR ', $conditions));
            }
        }

        /** @var list<PieceJointe> $resultat */
        $resultat = $qb->getQuery()->getResult();

        return $resultat;
    }
}
