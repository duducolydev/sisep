<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\TacheInitiativeRepository;
use Doctrine\ORM\Mapping as ORM;

/** Tâche d'une InitiativeActivite — ajoutée par le(s) responsable(s) de celle-ci. */
#[ORM\Entity(repositoryClass: TacheInitiativeRepository::class)]
#[ORM\Table(name: 'taches_initiative')]
class TacheInitiative
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: InitiativeActivite::class, inversedBy: 'taches')]
    #[ORM\JoinColumn(nullable: false)]
    private InitiativeActivite $initiativeActivite;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $ordre;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $responsable = null;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $dateRealisation = null;

    public function __construct(InitiativeActivite $initiativeActivite, string $libelle, int $ordre)
    {
        $this->initialiserUuid();
        $this->initiativeActivite = $initiativeActivite;
        $this->libelle = $libelle;
        $this->ordre = $ordre;
    }

    public function getInitiativeActivite(): InitiativeActivite
    {
        return $this->initiativeActivite;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): void
    {
        $this->ordre = $ordre;
    }

    /** Seul état saisi par un humain pour ce module (§ décision produit) : le statut affiché est calculé à partir de ceci, jamais choisi directement. */
    public function definirTerminee(bool $terminee): void
    {
        $this->dateRealisation = $terminee ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : null;
    }

    public function estTerminee(): bool
    {
        return null !== $this->dateRealisation;
    }

    public function getResponsable(): ?Utilisateur
    {
        return $this->responsable;
    }

    public function setResponsable(?Utilisateur $responsable): void
    {
        $this->responsable = $responsable;
    }

    public function getDateRealisation(): ?\DateTimeImmutable
    {
        return $this->dateRealisation;
    }
}
