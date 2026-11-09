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

    /**
     * L'activité est-elle dotée par ses lignes sur cet exercice ? En SQL direct :
     * le filtre de portée ne doit pas masquer une ligne confiée à une autre
     * structure, ce contrôle protège les totaux de tout le projet.
     */
    public function existeSurSesLignes(Activite $activite, Exercice $exercice): bool
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM dotations d
             JOIN sous_activites sa ON sa.id = d.sous_activite_id
             WHERE sa.activite_id = ? AND d.exercice_id = ? AND sa.supprime_le IS NULL',
            [$activite->getId(), $exercice->getId()],
        ) > 0;
    }
}
