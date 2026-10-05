<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\PermissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PermissionRepository::class)]
#[ORM\Table(name: 'permissions')]
class Permission
{
    use IdentifiantsTrait;

    #[ORM\Column(length: 80, unique: true)]
    private string $code;

    #[ORM\Column(length: 60)]
    private string $groupe;

    #[ORM\Column(length: 160)]
    private string $libelle;

    public function __construct(string $code, string $groupe, string $libelle)
    {
        $this->initialiserUuid();
        $this->code = $code;
        $this->groupe = $groupe;
        $this->libelle = $libelle;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getGroupe(): string
    {
        return $this->groupe;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }
}
