<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateurs')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    /** Verrouillage après 8 échecs sur 15 min (§ 7.1 du brief). */
    private const SEUIL_VERROUILLAGE = 8;
    private const FENETRE_VERROUILLAGE_MINUTES = 15;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $motDePasseHash;

    #[ORM\Column(length: 100)]
    private string $nom;

    #[ORM\Column(length: 100)]
    private string $prenom;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $fonction = null;

    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Structure $structure = null;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column]
    private bool $doitChangerMdp = true;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $derniereConnexion = null;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $echecsConnexion = 0;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $dernierEchecLe = null;

    /** @var Collection<int, UtilisateurRole> */
    #[ORM\OneToMany(targetEntity: UtilisateurRole::class, mappedBy: 'utilisateur')]
    private Collection $attributionsRoles;

    public function __construct(string $email, string $motDePasseHash, string $nom, string $prenom)
    {
        $this->initialiserUuid();
        $this->email = $email;
        $this->motDePasseHash = $motDePasseHash;
        $this->nom = $nom;
        $this->prenom = $prenom;
        $this->attributionsRoles = new ArrayCollection();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->motDePasseHash;
    }

    public function setMotDePasseHash(string $motDePasseHash): void
    {
        $this->motDePasseHash = $motDePasseHash;
    }

    /**
     * Les rôles Symfony (ROLE_*) servent uniquement de garde de firewall
     * (`IS_AUTHENTICATED_FULLY`). La matrice rôle × permission × portée
     * métier (§ 7.2) est portée par `attributionsRoles`, pas par ce tableau.
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): void
    {
        $this->nom = $nom;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): void
    {
        $this->prenom = $prenom;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): void
    {
        $this->fonction = $fonction;
    }

    public function getStructure(): ?Structure
    {
        return $this->structure;
    }

    public function setStructure(?Structure $structure): void
    {
        $this->structure = $structure;
    }

    public function estActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
    }

    public function doitChangerMdp(): bool
    {
        return $this->doitChangerMdp;
    }

    public function marquerMdpChange(): void
    {
        $this->doitChangerMdp = false;
    }

    /**
     * Après une réinitialisation par l'exploitant, le mot de passe a été vu
     * par quelqu'un d'autre que son titulaire : il ne doit servir qu'une fois.
     */
    public function exigerChangementMdp(): void
    {
        $this->doitChangerMdp = true;
    }

    public function getDerniereConnexion(): ?\DateTimeImmutable
    {
        return $this->derniereConnexion;
    }

    public function enregistrerConnexion(): void
    {
        $this->derniereConnexion = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->echecsConnexion = 0;
        $this->dernierEchecLe = null;
    }

    public function estVerrouille(\DateTimeImmutable $maintenant): bool
    {
        if ($this->echecsConnexion < self::SEUIL_VERROUILLAGE || null === $this->dernierEchecLe) {
            return false;
        }

        $finFenetre = $this->dernierEchecLe->modify(\sprintf('+%d minutes', self::FENETRE_VERROUILLAGE_MINUTES));

        return $maintenant < $finFenetre;
    }

    public function enregistrerEchecConnexion(\DateTimeImmutable $maintenant): void
    {
        $horsFenetre = null === $this->dernierEchecLe
            || $maintenant > $this->dernierEchecLe->modify(\sprintf('+%d minutes', self::FENETRE_VERROUILLAGE_MINUTES));

        $this->echecsConnexion = $horsFenetre ? 1 : $this->echecsConnexion + 1;
        $this->dernierEchecLe = $maintenant;
    }

    /** @return Collection<int, UtilisateurRole> */
    public function getAttributionsRoles(): Collection
    {
        return $this->attributionsRoles;
    }

    /**
     * Réservé au constructeur de UtilisateurRole (côté propriétaire de la
     * relation) : garde les deux côtés cohérents en mémoire avant même un
     * flush, notamment pour les tests qui construisent le graphe sans
     * Doctrine.
     */
    public function ajouterAttributionRole(UtilisateurRole $attribution): void
    {
        if (!$this->attributionsRoles->contains($attribution)) {
            $this->attributionsRoles->add($attribution);
        }
    }
}
