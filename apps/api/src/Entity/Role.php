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

    /**
     * Un rôle d'administration (admin.*), ou qui cumule saisie et validation
     * du budget, ne s'attribue que sur tout le projet.
     *
     * Limité à une structure, il n'ouvrirait aucun écran d'administration —
     * le serveur ne les sert qu'en portée globale — mais garderait tous ses
     * droits d'écriture sur cette structure, cumul saisie-validation compris :
     * trompeur et dangereux à la fois. Constaté le 29/10/2026 sur un compte
     * administrateur limité à la DSID.
     *
     * La règle se lit sur les permissions, pas sur le code du rôle : elle
     * couvre l'administrateur, le coordonnateur et le responsable
     * suivi-évaluation, et tout rôle que la matrice ferait entrer dans le
     * même cas.
     */
    public function exigePorteeGlobale(): bool
    {
        return self::permissionsExigentPorteeGlobale(
            array_map(static fn (Permission $p) => $p->getCode(), $this->permissions->toArray()),
        );
    }

    /** @param array<string> $codes */
    public static function permissionsExigentPorteeGlobale(array $codes): bool
    {
        foreach ($codes as $code) {
            if (str_starts_with($code, 'admin.')) {
                return true;
            }
        }

        $saisit = [] !== array_intersect($codes, ['budget.engagement.saisir', 'budget.depense.saisir']);
        $valide = [] !== array_intersect($codes, ['budget.engagement.valider', 'budget.depense.valider']);

        return $saisit && $valide;
    }
}
