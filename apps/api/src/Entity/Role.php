<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'roles')]
class Role
{
    use IdentifiantsTrait;

    #[ORM\Column(length: 60, unique: true)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Rôle du socle (§ 7.2) — non supprimable depuis l'administration. */
    #[ORM\Column]
    private bool $systeme = false;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'role_permissions')]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'permission_id', referencedColumnName: 'id')]
    private Collection $permissions;

    public function __construct(string $code, string $libelle, bool $systeme = false)
    {
        $this->initialiserUuid();
        $this->code = $code;
        $this->libelle = $libelle;
        $this->systeme = $systeme;
        $this->permissions = new ArrayCollection();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function estSysteme(): bool
    {
        return $this->systeme;
    }

    /** @return Collection<int, Permission> */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function accorder(Permission $permission): void
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }
    }

    public function revoquer(Permission $permission): void
    {
        $this->permissions->removeElement($permission);
    }
}
