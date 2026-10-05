<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\InitiativeActiviteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Activité d'un projet de suivi (Initiative), avec un ou plusieurs
 * responsables individuels (équipe PHASAOC ou sectoriels) — portée par
 * personne, pas par structure, donc hors du mécanisme
 * PorteeParStructureInterface utilisé pour les activités du PTBA.
 * L'avancement n'est jamais stocké : calculé à l'affichage à partir des
 * tâches (InitiativeNormalizer), comme l'était Diligence::tauxExecution.
 */
#[ORM\Entity(repositoryClass: InitiativeActiviteRepository::class)]
#[ORM\Table(name: 'initiative_activites')]
class InitiativeActivite
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Initiative::class, inversedBy: 'activites')]
    #[ORM\JoinColumn(nullable: false)]
    private Initiative $initiative;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $ordre;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    /** @var Collection<int, Utilisateur> */
    #[ORM\ManyToMany(targetEntity: Utilisateur::class)]
    #[ORM\JoinTable(name: 'initiative_activite_responsables')]
    #[ORM\JoinColumn(name: 'initiative_activite_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'utilisateur_id', referencedColumnName: 'id')]
    private Collection $responsables;

    /** @var Collection<int, TacheInitiative> */
    #[ORM\OneToMany(targetEntity: TacheInitiative::class, mappedBy: 'initiativeActivite')]
    private Collection $taches;

    public function __construct(Initiative $initiative, string $libelle, int $ordre)
    {
        $this->initialiserUuid();
        $this->initiative = $initiative;
        $this->libelle = $libelle;
        $this->ordre = $ordre;
        $this->responsables = new ArrayCollection();
        $this->taches = new ArrayCollection();
    }

    public function getInitiative(): Initiative
    {
        return $this->initiative;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
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

    /** @return Collection<int, Utilisateur> */
    public function getResponsables(): Collection
    {
        return $this->responsables;
    }

    public function ajouterResponsable(Utilisateur $utilisateur): void
    {
        if (!$this->responsables->contains($utilisateur)) {
            $this->responsables->add($utilisateur);
        }
    }

    public function estResponsable(Utilisateur $utilisateur): bool
    {
        return $this->responsables->contains($utilisateur);
    }

    /** @return Collection<int, TacheInitiative> */
    public function getTaches(): Collection
    {
        return $this->taches;
    }
}
