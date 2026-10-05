<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\PointFocalRepository;
use App\Security\PorteeParStructureInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PointFocalRepository::class)]
#[ORM\Table(name: 'points_focaux')]
class PointFocal implements PorteeParStructureInterface
{
    use IdentifiantsTrait;

    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Structure $structure;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 160)]
    private string $nomComplet;

    #[ORM\Column(length: 160)]
    private string $fonction;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $email = null;

    #[ORM\Column]
    private bool $principal = false;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateDesignation = null;

    #[ORM\Column]
    private bool $actif = true;

    public function __construct(Structure $structure, string $nomComplet, string $fonction)
    {
        $this->initialiserUuid();
        $this->structure = $structure;
        $this->nomComplet = $nomComplet;
        $this->fonction = $fonction;
    }

    public function getStructure(): Structure
    {
        return $this->structure;
    }

    public function getStructurePourPortee(): ?Structure
    {
        return $this->structure;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): void
    {
        $this->utilisateur = $utilisateur;
    }

    public function getNomComplet(): string
    {
        return $this->nomComplet;
    }

    public function setNomComplet(string $nomComplet): void
    {
        $this->nomComplet = $nomComplet;
    }

    public function getFonction(): string
    {
        return $this->fonction;
    }

    public function setFonction(string $fonction): void
    {
        $this->fonction = $fonction;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): void
    {
        $this->telephone = $telephone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function estPrincipal(): bool
    {
        return $this->principal;
    }

    public function setPrincipal(bool $principal): void
    {
        $this->principal = $principal;
    }

    public function getDateDesignation(): ?\DateTimeImmutable
    {
        return $this->dateDesignation;
    }

    public function setDateDesignation(?\DateTimeImmutable $dateDesignation): void
    {
        $this->dateDesignation = $dateDesignation;
    }

    public function estActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
    }
}
