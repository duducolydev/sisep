<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\InvitationIndicateurRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Octroi ponctuel, par personne et par indicateur, de indicateurs.saisir
 * (et, par extension, indicateurs.consulter pour ce seul indicateur) à un
 * sectoriel — sans lui redonner la permission globale (§ décision, module
 * Indicateurs centralisé à l'équipe PHASAOC depuis Workstream 2).
 *
 * Pas de suppression logique : un octroi de type ACL se révoque par
 * suppression physique, comme UtilisateurRole (CLAUDE.md § suppression
 * logique réservée aux entités budgétaires/historiques).
 */
#[ORM\Entity(repositoryClass: InvitationIndicateurRepository::class)]
#[ORM\Table(name: 'invitations_indicateurs')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_indicateur_utilisateur', columns: ['indicateur_id', 'utilisateur_id'])]
class InvitationIndicateur
{
    use IdentifiantsTrait;

    #[ORM\ManyToOne(targetEntity: Indicateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Indicateur $indicateur;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $utilisateur;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $invitePar;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $inviteLe;

    public function __construct(Indicateur $indicateur, Utilisateur $utilisateur, Utilisateur $invitePar)
    {
        $this->initialiserUuid();
        $this->indicateur = $indicateur;
        $this->utilisateur = $utilisateur;
        $this->invitePar = $invitePar;
        $this->inviteLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getIndicateur(): Indicateur
    {
        return $this->indicateur;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getInvitePar(): Utilisateur
    {
        return $this->invitePar;
    }

    public function getInviteLe(): \DateTimeImmutable
    {
        return $this->inviteLe;
    }
}
