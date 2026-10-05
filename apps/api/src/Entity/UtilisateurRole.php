<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UtilisateurRoleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Portée : structure_id NULL = portée globale, sinon l'utilisateur n'a le
 * rôle que sur les entités de cette structure (§ 4.5, § 7.2).
 *
 * Écart assumé par rapport au brief : celui-ci propose une clé primaire
 * composite (utilisateur_id, role_id, structure_id). MySQL interdit NULL
 * dans une colonne de clé primaire, ce qui rend cette définition
 * inapplicable telle quelle pour la portée globale. On utilise donc un id
 * de substitution, avec un index unique sur le triplet — MySQL traite
 * plusieurs NULL comme distincts dans un index unique, donc l'unicité
 * stricte d'une attribution globale (structure_id NULL) n'est pas garantie
 * en base ; à vérifier en service avant insertion (RoleAssignmentService).
 */
#[ORM\Entity(repositoryClass: UtilisateurRoleRepository::class)]
#[ORM\Table(name: 'utilisateur_roles')]
#[ORM\UniqueConstraint(name: 'uniq_utilisateur_role_structure', columns: ['utilisateur_id', 'role_id', 'structure_id'])]
class UtilisateurRole
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'attributionsRoles')]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $utilisateur;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Role $role;

    /** NULL = portée globale. */
    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Structure $structure = null;

    public function __construct(Utilisateur $utilisateur, Role $role, ?Structure $structure = null)
    {
        $this->utilisateur = $utilisateur;
        $this->role = $role;
        $this->structure = $structure;
        $utilisateur->ajouterAttributionRole($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getStructure(): ?Structure
    {
        return $this->structure;
    }

    public function estPorteeGlobale(): bool
    {
        return null === $this->structure;
    }
}
