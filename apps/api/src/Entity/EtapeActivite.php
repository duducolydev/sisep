<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\EtapeActiviteRepository;
use App\Security\PorteeParStructureInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Étape de réalisation d'une activité (§ 6 du CR du 22/09/2026) : c'est elle
 * qui porte désormais le suivi d'avancement, à la place de la sous-activité.
 *
 * Les quatre champs demandés en réunion sont ici : le niveau atteint
 * (`avancement`), la valeur actuelle, la date de mise à jour et
 * l'observation. Les pièces justificatives passent par PieceJointe
 * (entiteType `etape_activite`), comme pour les activités et les indicateurs.
 *
 * `valeurActuelle` est une chaîne décimale et non un Montant : une étape peut
 * valoir 12 communes couvertes ou 3,5 mois, pas seulement des FCFA — même
 * raisonnement que IndicateurValeur.
 *
 * La portée suit celle de l'activité : qui peut modifier l'activité renseigne
 * ses étapes, sans attribution supplémentaire.
 */
#[ORM\Entity(repositoryClass: EtapeActiviteRepository::class)]
#[ORM\Table(name: 'etapes_activite')]
class EtapeActivite implements PorteeParStructureInterface
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Activite::class, inversedBy: 'etapes')]
    #[ORM\JoinColumn(nullable: false)]
    private Activite $activite;

    #[ORM\Column(length: 160)]
    private string $libelle;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $ordre;

    /** Niveau atteint, de 0 à 100 — la progression de l'activité s'en déduit, elle n'est jamais stockée. */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $avancement = 0;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4, nullable: true)]
    private ?string $valeurActuelle = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $unite = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    /** Date du dernier renseignement, distincte de modifieLe : elle est saisie, pas technique. */
    #[ORM\Column(name: 'date_mise_a_jour', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateMiseAJour = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observation = null;

    /** @var Collection<int, Livrable> */
    #[ORM\OneToMany(targetEntity: Livrable::class, mappedBy: 'etape', cascade: ['persist'])]
    private Collection $livrables;

    public function __construct(Activite $activite, string $libelle, int $ordre)
    {
        $this->initialiserUuid();
        $this->activite = $activite;
        $this->libelle = $libelle;
        $this->ordre = $ordre;
        $this->livrables = new ArrayCollection();
        $activite->ajouterEtape($this);
    }

    public function getActivite(): Activite
    {
        return $this->activite;
    }

    public function getStructurePourPortee(): ?Structure
    {
        return $this->activite->getStructureResponsable();
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

    public function setOrdre(int $ordre): void
    {
        $this->ordre = $ordre;
    }

    public function getAvancement(): int
    {
        return $this->avancement;
    }

    public function getValeurActuelle(): ?string
    {
        return $this->valeurActuelle;
    }

    public function getUnite(): ?string
    {
        return $this->unite;
    }

    public function setUnite(?string $unite): void
    {
        $this->unite = $unite;
    }

    public function getEcheance(): ?\DateTimeImmutable
    {
        return $this->echeance;
    }

    public function setEcheance(?\DateTimeImmutable $echeance): void
    {
        $this->echeance = $echeance;
    }

    public function getDateMiseAJour(): ?\DateTimeImmutable
    {
        return $this->dateMiseAJour;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    /**
     * Renseigne l'étape en un seul geste : les quatre champs du § 6 bougent
     * ensemble, et la date de mise à jour ne peut pas être oubliée — c'est
     * elle qui alimente les rappels d'étape sans nouvelle depuis trop
     * longtemps.
     */
    public function renseigner(int $avancement, ?string $valeurActuelle, ?string $observation, \DateTimeImmutable $dateMiseAJour): void
    {
        $this->avancement = max(0, min(100, $avancement));
        $this->valeurActuelle = $valeurActuelle;
        $this->observation = $observation;
        $this->dateMiseAJour = $dateMiseAJour;
    }

    /** @return Collection<int, Livrable> */
    public function getLivrables(): Collection
    {
        return $this->livrables;
    }

    /** Réservé au constructeur de Livrable (côté propriétaire) — garde les deux côtés cohérents avant même un flush. */
    public function ajouterLivrable(Livrable $livrable): void
    {
        if (!$this->livrables->contains($livrable)) {
            $this->livrables->add($livrable);
        }
    }
}
