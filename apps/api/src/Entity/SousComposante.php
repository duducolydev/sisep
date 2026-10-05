<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\SousComposanteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SousComposanteRepository::class)]
#[ORM\Table(name: 'sous_composantes')]
#[ORM\UniqueConstraint(name: 'uniq_sous_composante_code', columns: ['composante_id', 'code', 'supprime_le'])]
class SousComposante
{
    use IdentifiantsTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Composante::class, inversedBy: 'sousComposantes')]
    #[ORM\JoinColumn(nullable: false)]
    private Composante $composante;

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

    /** @var Collection<int, Activite> */
    #[ORM\OneToMany(targetEntity: Activite::class, mappedBy: 'sousComposante')]
    private Collection $activites;

    public function __construct(Composante $composante, string $code, string $libelle)
    {
        $this->initialiserUuid();
        $this->composante = $composante;
        $this->code = $code;
        $this->libelle = $libelle;
        $this->activites = new ArrayCollection();
    }

    public function getComposante(): Composante
    {
        return $this->composante;
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
