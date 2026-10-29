<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JetonReinitialisation;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JetonReinitialisation>
 */
class JetonReinitialisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JetonReinitialisation::class);
    }

    public function trouverParJeton(string $jeton): ?JetonReinitialisation
    {
        return $this->findOneBy(['empreinte' => JetonReinitialisation::empreinte($jeton)]);
    }

    public function dernierPour(Utilisateur $utilisateur): ?JetonReinitialisation
    {
        return $this->findOneBy(['utilisateur' => $utilisateur], ['creeLe' => 'DESC']);
    }

    /** @return list<JetonReinitialisation> */
    public function tousPour(Utilisateur $utilisateur): array
    {
        return $this->findBy(['utilisateur' => $utilisateur]);
    }

    /** Ménage à chaque demande : la table ne garde que des liens encore valables. */
    public function purgerExpires(\DateTimeImmutable $maintenant): void
    {
        $this->createQueryBuilder('j')
            ->delete()
            ->where('j.expireLe <= :maintenant')
            ->setParameter('maintenant', $maintenant)
            ->getQuery()
            ->execute();
    }
}
