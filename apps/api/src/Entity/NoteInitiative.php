<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\NoteInitiativeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Observation libre ajoutée au fil de l'eau sur une InitiativeActivite ou
 * une TacheInitiative — polymorphe comme PieceJointe (entiteType +
 * entiteId), pas d'édition ni de suppression (§ décision produit, même
 * choix que l'ancien NoteDiligence).
 */
#[ORM\Entity(repositoryClass: NoteInitiativeRepository::class)]
#[ORM\Table(name: 'notes_initiative')]
#[ORM\Index(name: 'idx_note_initiative_entite', columns: ['entite_type', 'entite_id'])]
class NoteInitiative
{
    use IdentifiantsTrait;

    #[ORM\Column(length: 40)]
    private string $entiteType;

    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private int $entiteId;

    #[ORM\Column(type: 'text')]
    private string $contenu;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $creePar = null;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $creeLe;

    public function __construct(string $entiteType, int $entiteId, string $contenu, ?Utilisateur $creePar)
    {
        $this->initialiserUuid();
        $this->entiteType = $entiteType;
        $this->entiteId = $entiteId;
        $this->contenu = $contenu;
        $this->creePar = $creePar;
        $this->creeLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getEntiteType(): string
    {
        return $this->entiteType;
    }

    public function getEntiteId(): int
    {
        return $this->entiteId;
    }

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
