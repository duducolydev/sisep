<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Enum\StatutActivite;
use App\Repository\ActiviteRepository;
use App\Security\PorteeParStructureInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le budget est porté par l'activité, jamais par la sous-activité (§ 2.2).
 * `statut` est saisi ; la vigilance (retard / risque élevé) est calculée à
 * la volée par VigilanceCalculator et n'est jamais stockée (§ 4.2, § 5.3).
 */
#[ORM\Entity(repositoryClass: ActiviteRepository::class)]
#[ORM\Table(name: 'activites')]
#[ORM\UniqueConstraint(name: 'uniq_activite_code', columns: ['sous_composante_id', 'code', 'supprime_le'])]
#[ORM\Index(name: 'idx_activite_structure_responsable', columns: ['structure_responsable_id'])]
#[ORM\Index(name: 'idx_activite_statut', columns: ['statut'])]
class Activite implements PorteeParStructureInterface
{
    use IdentifiantsTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: SousComposante::class, inversedBy: 'activites')]
    #[ORM\JoinColumn(nullable: false)]
    private SousComposante $sousComposante;

    #[ORM\Column(length: 20)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Structure $structureResponsable;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFinPrevue = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFinReelle = null;

    #[ORM\Column(length: 20, enumType: StatutActivite::class)]
    private StatutActivite $statut = StatutActivite::PLANIFIEE;

    #[ORM\Column(type: 'smallint')]
    private int $ordre = 0;

    /** @var Collection<int, SousActivite> */
    #[ORM\OneToMany(targetEntity: SousActivite::class, mappedBy: 'activite')]
    private Collection $sousActivites;

    /** @var Collection<int, EtapeActivite> */
    #[ORM\OneToMany(targetEntity: EtapeActivite::class, mappedBy: 'activite', cascade: ['persist'])]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $etapes;

    /** @var Collection<int, Dotation> */
    #[ORM\OneToMany(targetEntity: Dotation::class, mappedBy: 'activite')]
    private Collection $dotations;

    /** @var Collection<int, Engagement> */
    #[ORM\OneToMany(targetEntity: Engagement::class, mappedBy: 'activite')]
    private Collection $engagements;

    public function __construct(SousComposante $sousComposante, string $code, string $libelle, Structure $structureResponsable)
    {
        $this->initialiserUuid();
        $this->sousComposante = $sousComposante;
        $this->code = $code;
        $this->libelle = $libelle;
        $this->structureResponsable = $structureResponsable;
        $this->sousActivites = new ArrayCollection();
        $this->etapes = new ArrayCollection();
        $this->dotations = new ArrayCollection();
        $this->engagements = new ArrayCollection();
    }

    public function getSousComposante(): SousComposante
    {
        return $this->sousComposante;
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

    public function getStructureResponsable(): Structure
    {
        return $this->structureResponsable;
    }

    public function getStructurePourPortee(): ?Structure
    {
        return $this->structureResponsable;
    }

    public function setStructureResponsable(Structure $structureResponsable): void
    {
        $this->structureResponsable = $structureResponsable;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): void
    {
        $this->dateDebut = $dateDebut;
    }

    public function getDateFinPrevue(): ?\DateTimeImmutable
    {
        return $this->dateFinPrevue;
    }

    public function setDateFinPrevue(?\DateTimeImmutable $dateFinPrevue): void
    {
        $this->dateFinPrevue = $dateFinPrevue;
    }

    public function getDateFinReelle(): ?\DateTimeImmutable
    {
        return $this->dateFinReelle;
    }

    public function setDateFinReelle(?\DateTimeImmutable $dateFinReelle): void
    {
        $this->dateFinReelle = $dateFinReelle;
    }

    public function getStatut(): StatutActivite
    {
        return $this->statut;
    }

    public function setStatut(StatutActivite $statut): void
    {
        $this->statut = $statut;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): void
    {
        $this->ordre = $ordre;
    }

    /** @return Collection<int, SousActivite> */
    public function getSousActivites(): Collection
    {
        return $this->sousActivites;
    }

    /** @return Collection<int, SousActivite> */
    public function getSousActivitesActives(): Collection
    {
        return $this->sousActivites->filter(fn (SousActivite $sa) => !$sa->estSupprime());
    }

    /** @return Collection<int, EtapeActivite> */
    public function getEtapes(): Collection
    {
        return $this->etapes;
    }

    /**
     * Toutes les étapes actives de l'activité, y compris celles de ses lignes.
     *
     * @return Collection<int, EtapeActivite>
     */
    public function getEtapesActives(): Collection
    {
        return $this->etapes->filter(fn (EtapeActivite $e) => !$e->estSupprime()
            && (null === $e->getSousActivite() || !$e->getSousActivite()->estSupprime()));
    }

    /**
     * Étapes propres de l'activité, hors lignes (sous-activités).
     *
     * @return Collection<int, EtapeActivite>
     */
    public function getEtapesPropres(): Collection
    {
        return $this->getEtapesActives()->filter(fn (EtapeActivite $e) => null === $e->getSousActivite());
    }

    /** Réservé au constructeur de EtapeActivite (côté propriétaire) — garde les deux côtés cohérents avant même un flush. */
    public function ajouterEtape(EtapeActivite $etape): void
    {
        if (!$this->etapes->contains($etape)) {
            $this->etapes->add($etape);
        }
    }

    /** @return Collection<int, Dotation> */
    public function getDotations(): Collection
    {
        return $this->dotations;
    }

    /** @return Collection<int, Engagement> */
    public function getEngagements(): Collection
    {
        return $this->engagements;
    }
}
