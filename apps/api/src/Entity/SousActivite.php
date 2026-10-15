<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Enum\StatutActivite;
use App\Repository\SousActiviteRepository;
use App\Security\PorteeParStructureInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * La sous-activité porte l'avancement physique et l'échéance (§ 2.2).
 * `avancement` est la valeur courante, mise à jour par un listener Doctrine
 * à chaque insertion de Realisation (§ 4.6) — ne jamais l'écrire directement
 * en dehors de ce mécanisme.
 *
 * Peut aussi porter son propre budget (dotation/engagement), en alignement
 * avec le PTBA réel du PHASAOC qui chiffre certaines lignes à ce niveau
 * (ex. RGPH-5, EHCVM) — cf. Dotation/Engagement::getCible().
 */
#[ORM\Entity(repositoryClass: SousActiviteRepository::class)]
#[ORM\Table(name: 'sous_activites')]
class SousActivite implements PorteeParStructureInterface
{
    use IdentifiantsTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Activite::class, inversedBy: 'sousActivites')]
    #[ORM\JoinColumn(nullable: false)]
    private Activite $activite;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Structure $structureResponsable = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    #[ORM\Column(length: 20, enumType: StatutActivite::class)]
    private StatutActivite $statut = StatutActivite::PLANIFIEE;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $avancement = 0;

    #[ORM\Column(type: 'smallint')]
    private int $ordre = 0;

    /** @var Collection<int, Realisation> */
    #[ORM\OneToMany(targetEntity: Realisation::class, mappedBy: 'sousActivite')]
    private Collection $realisations;

    /** @var Collection<int, Dotation> */
    #[ORM\OneToMany(targetEntity: Dotation::class, mappedBy: 'sousActivite')]
    private Collection $dotations;

    /** @var Collection<int, Engagement> */
    #[ORM\OneToMany(targetEntity: Engagement::class, mappedBy: 'sousActivite')]
    private Collection $engagements;

    /** @var Collection<int, EtapeActivite> */
    #[ORM\OneToMany(targetEntity: EtapeActivite::class, mappedBy: 'sousActivite')]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $etapes;

    public function __construct(Activite $activite, string $libelle)
    {
        $this->initialiserUuid();
        $this->activite = $activite;
        $this->libelle = $libelle;
        $this->realisations = new ArrayCollection();
        $this->dotations = new ArrayCollection();
        $this->engagements = new ArrayCollection();
        $this->etapes = new ArrayCollection();
    }

    /** @return Collection<int, EtapeActivite> */
    public function getEtapesActives(): Collection
    {
        return $this->etapes->filter(fn (EtapeActivite $e) => !$e->estSupprime());
    }

    /** Réservé au constructeur de EtapeActivite — garde les deux côtés cohérents avant même un flush. */
    public function ajouterEtape(EtapeActivite $etape): void
    {
        if (!$this->etapes->contains($etape)) {
            $this->etapes->add($etape);
        }
    }

    public function getActivite(): Activite
    {
        return $this->activite;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getStructureResponsable(): ?Structure
    {
        return $this->structureResponsable;
    }

    public function setStructureResponsable(?Structure $structureResponsable): void
    {
        $this->structureResponsable = $structureResponsable;
    }

    /** À défaut de responsable propre, la portée retombe sur celle de l'activité parente (§ 7.2). */
    public function getStructurePourPortee(): ?Structure
    {
        return $this->structureResponsable ?? $this->activite->getStructurePourPortee();
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): void
    {
        $this->dateDebut = $dateDebut;
    }

    public function getEcheance(): ?\DateTimeImmutable
    {
        return $this->echeance;
    }

    public function setEcheance(?\DateTimeImmutable $echeance): void
    {
        $this->echeance = $echeance;
    }

    public function getStatut(): StatutActivite
    {
        return $this->statut;
    }

    public function setStatut(StatutActivite $statut): void
    {
        $this->statut = $statut;
    }

    public function getAvancement(): int
    {
        return $this->avancement;
    }

    /** Réservé au listener Doctrine sur Realisation — § 4.6 du brief. */
    public function definirAvancementCourant(int $avancement): void
    {
        if ($avancement < 0 || $avancement > 100) {
            throw new \InvalidArgumentException('L\'avancement doit être compris entre 0 et 100.');
        }

        $this->avancement = $avancement;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): void
    {
        $this->ordre = $ordre;
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
