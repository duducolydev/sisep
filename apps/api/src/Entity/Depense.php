<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Enum\StatutEcriture;
use App\Repository\DepenseRepository;
use App\ValueObject\Montant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[ORM\Table(name: 'depenses')]
#[ORM\Index(name: 'idx_depense_engagement_statut', columns: ['engagement_id', 'statut'])]
class Depense
{
    use IdentifiantsTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Engagement::class, inversedBy: 'depenses')]
    #[ORM\JoinColumn(nullable: false)]
    private Engagement $engagement;

    #[ORM\Column(type: 'montant')]
    private Montant $montant;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateDepense;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $referencePaiement = null;

    #[ORM\Column(length: 20, enumType: StatutEcriture::class)]
    private StatutEcriture $statut = StatutEcriture::BROUILLON;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $creePar;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $validePar = null;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    public function __construct(Engagement $engagement, Montant $montant, \DateTimeImmutable $dateDepense, Utilisateur $creePar)
    {
        $this->initialiserUuid();
        $this->engagement = $engagement;
        $this->montant = $montant;
        $this->dateDepense = $dateDepense;
        $this->creePar = $creePar;
    }

    public function getEngagement(): Engagement
    {
        return $this->engagement;
    }

    public function getMontant(): Montant
    {
        return $this->montant;
    }

    public function getDateDepense(): \DateTimeImmutable
    {
        return $this->dateDepense;
    }

    public function getReferencePaiement(): ?string
    {
        return $this->referencePaiement;
    }

    public function setReferencePaiement(?string $referencePaiement): void
    {
        $this->referencePaiement = $referencePaiement;
    }

    public function getStatut(): StatutEcriture
    {
        return $this->statut;
    }

    public function getCreePar(): Utilisateur
    {
        return $this->creePar;
    }

    public function getValidePar(): ?Utilisateur
    {
        return $this->validePar;
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function valider(Utilisateur $validePar): void
    {
        $this->statut = StatutEcriture::VALIDE;
        $this->validePar = $validePar;
        $this->valideLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function annuler(): void
    {
        $this->statut = StatutEcriture::ANNULE;
    }
}
