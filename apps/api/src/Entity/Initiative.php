<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\InitiativeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Suivi d'un projet transverse (« Projets » à l'écran — nommée Initiative
 * ici pour ne pas entrer en collision avec l'entité racine Projet du PTBA,
 * cf. Projet::class). Indépendante du PTBA par défaut ; peut être rattachée
 * à UNE composante ou UNE sous-composante (jamais les deux) pour que son
 * taux d'exécution budgétaire PTBA s'affiche dans ce module — cf.
 * InitiativeNormalizer.
 */
#[ORM\Entity(repositoryClass: InitiativeRepository::class)]
#[ORM\Table(name: 'initiatives')]
class Initiative
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\Column(length: 160)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Composante::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Composante $composante = null;

    #[ORM\ManyToOne(targetEntity: SousComposante::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?SousComposante $sousComposante = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $creePar;

    /** @var Collection<int, InitiativeActivite> */
    #[ORM\OneToMany(targetEntity: InitiativeActivite::class, mappedBy: 'initiative')]
    private Collection $activites;

    public function __construct(string $libelle, Utilisateur $creePar)
    {
        $this->initialiserUuid();
        $this->libelle = $libelle;
        $this->creePar = $creePar;
        $this->activites = new ArrayCollection();
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

    public function getComposante(): ?Composante
    {
        return $this->composante;
    }

    public function getSousComposante(): ?SousComposante
    {
        return $this->sousComposante;
    }

    /** Au plus une des deux cibles PTBA — jamais les deux (§ décision produit). */
    public function definirLienPtba(?Composante $composante, ?SousComposante $sousComposante): void
    {
        $this->composante = $composante;
        $this->sousComposante = $sousComposante;
    }

    public function getCreePar(): Utilisateur
    {
        return $this->creePar;
    }

    /** @return Collection<int, InitiativeActivite> */
    public function getActivites(): Collection
    {
        return $this->activites;
    }
}
