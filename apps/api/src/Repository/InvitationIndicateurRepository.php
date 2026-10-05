<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Indicateur;
use App\Entity\InvitationIndicateur;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvitationIndicateur>
 */
class InvitationIndicateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvitationIndicateur::class);
    }

    public function estInvite(Indicateur $indicateur, Utilisateur $utilisateur): bool
    {
        return null !== $this->findOneBy(['indicateur' => $indicateur, 'utilisateur' => $utilisateur]);
    }

    public function trouver(Indicateur $indicateur, Utilisateur $utilisateur): ?InvitationIndicateur
    {
        return $this->findOneBy(['indicateur' => $indicateur, 'utilisateur' => $utilisateur]);
    }

    /** @return list<Indicateur> */
    public function indicateursInvites(Utilisateur $utilisateur): array
    {
        $invitations = $this->findBy(['utilisateur' => $utilisateur]);

        return array_map(static fn (InvitationIndicateur $i) => $i->getIndicateur(), $invitations);
    }

    /** @return list<InvitationIndicateur> */
    public function lister(Indicateur $indicateur): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.indicateur = :indicateur')
            ->setParameter('indicateur', $indicateur)
            ->orderBy('i.inviteLe', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
