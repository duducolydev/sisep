<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\ComposanteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ComposanteRepository::class)]
#[ORM\Table(name: 'composantes')]
#[ORM\UniqueConstraint(name: 'uniq_composante_projet_code', columns: ['projet_id', 'code', 'supprime_le'])]
class Composante
{
    use IdentifiantsTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Projet::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Projet $projet;

    #[ORM\Column(length: 10)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Structure $structurePilote = null;

    #[ORM\Column(type: 'smallint')]
    private int $ordre = 0;

    /** @var Collection<int, SousComposante> */
    #[ORM\OneToMany(targetEntity: SousComposante::class, mappedBy: 'composante')]
    private Collection $sousComposantes;

    public function __construct(Projet $projet, string $code, string $libelle)
    {
        $this->initialiserUuid();
        $this->projet = $projet;
        $this->code = $code;
        $this->libelle = $libelle;
        $this->sousComposantes = new ArrayCollection();
    }

    public function getProjet(): Projet
    {
        return $this->projet;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getStructurePilote(): ?Structure
    {
        return $this->structurePilote;
    }

    public function setStructurePilote(?Structure $structurePilote): void
    {
        $this->structurePilote = $structurePilote;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): void
    {
        $this->ordre = $ordre;
    }
}
