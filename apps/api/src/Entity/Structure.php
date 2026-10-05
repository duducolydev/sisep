<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Enum\TypeStructure;
use App\Repository\StructureRepository;
use App\Security\PorteeParStructureInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StructureRepository::class)]
#[ORM\Table(name: 'structures')]
#[ORM\UniqueConstraint(name: 'uniq_structure_sigle', columns: ['sigle', 'supprime_le'])]
class Structure implements PorteeParStructureInterface
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\Column(length: 20)]
    private string $sigle;

    #[ORM\Column(length: 255)]
    private string $denomination;

    #[ORM\Column(length: 30, enumType: TypeStructure::class)]
    private TypeStructure $type;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column]
    private bool $actif = true;

    public function __construct(string $sigle, string $denomination, TypeStructure $type)
    {
        $this->initialiserUuid();
        $this->sigle = $sigle;
        $this->denomination = $denomination;
        $this->type = $type;
    }

    public function getSigle(): string
    {
        return $this->sigle;
    }

    public function getDenomination(): string
    {
        return $this->denomination;
    }

    public function getType(): TypeStructure
    {
        return $this->type;
    }

    public function setType(TypeStructure $type): void
    {
        $this->type = $type;
    }

    public function setDenomination(string $denomination): void
    {
        $this->denomination = $denomination;
    }

    public function setSigle(string $sigle): void
    {
        $this->sigle = $sigle;
    }

    /** Une structure est sa propre portée (§ 7.2) : structures.gerer et points_focaux.gerer peuvent être attribuées par structure. */
    public function getStructurePourPortee(): ?Structure
    {
        return $this;
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

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): void
    {
        $this->adresse = $adresse;
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
