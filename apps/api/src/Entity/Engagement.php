<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Enum\NatureEngagement;
use App\Enum\StatutEcriture;
use App\Repository\EngagementRepository;
use App\ValueObject\Montant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * La dépense se rattache à l'engagement, pas directement à l'activité :
 * c'est ce qui rend impossible de décaisser plus qu'on n'a engagé (§ 4.3).
 * Le contrôle de plafond (§ 5.1) verrouille la ligne de dotation correspondante
 * — appliqué en service, jamais uniquement côté front.
 *
 * Cible activité OU sous-activité, comme Dotation (§ 6.2, alignement PTBA
 * PHASAOC) — jamais les deux, contrainte `chk_engagement_cible` en base.
 */
#[ORM\Entity(repositoryClass: EngagementRepository::class)]
#[ORM\Table(name: 'engagements')]
#[ORM\Index(name: 'idx_engagement_activite_exercice_statut', columns: ['activite_id', 'exercice_id', 'statut'])]
#[ORM\Index(name: 'idx_engagement_sous_activite_exercice_statut', columns: ['sous_activite_id', 'exercice_id', 'statut'])]
class Engagement
{
    use IdentifiantsTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Activite::class, inversedBy: 'engagements')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Activite $activite = null;

    #[ORM\ManyToOne(targetEntity: SousActivite::class, inversedBy: 'engagements')]
    #[ORM\JoinColumn(nullable: true)]
    private ?SousActivite $sousActivite = null;

    #[ORM\ManyToOne(targetEntity: Exercice::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Exercice $exercice;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $referenceMarche = null;

    #[ORM\Column(length: 30, enumType: NatureEngagement::class)]
    private NatureEngagement $nature;

    #[ORM\Column(type: 'montant')]
    private Montant $montant;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateEngagement;

    #[ORM\Column(length: 20, enumType: StatutEcriture::class)]
    private StatutEcriture $statut = StatutEcriture::BROUILLON;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observations = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $creePar;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $validePar = null;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    /** @var Collection<int, Depense> */
    #[ORM\OneToMany(targetEntity: Depense::class, mappedBy: 'engagement')]
    private Collection $depenses;

    public function __construct(
        Activite|SousActivite $cible,
        Exercice $exercice,
        NatureEngagement $nature,
        Montant $montant,
        \DateTimeImmutable $dateEngagement,
        Utilisateur $creePar,
    ) {
        $this->initialiserUuid();
        if ($cible instanceof Activite) {
            $this->activite = $cible;
        } else {
            $this->sousActivite = $cible;
        }
        $this->exercice = $exercice;
        $this->nature = $nature;
        $this->montant = $montant;
        $this->dateEngagement = $dateEngagement;
        $this->creePar = $creePar;
        $this->depenses = new ArrayCollection();
    }

    public function getActivite(): ?Activite
    {
        return $this->activite;
    }

    public function getSousActivite(): ?SousActivite
    {
        return $this->sousActivite;
    }

    /** L'activité ou la sous-activité directement ciblée par cet engagement. */
    public function getCible(): Activite|SousActivite
    {
        return $this->activite ?? $this->sousActivite;
    }

    /** Résout toujours l'activité porteuse, même quand l'engagement cible une de ses sous-activités. */
    public function getActiviteParente(): Activite
    {
        return $this->activite ?? $this->sousActivite->getActivite();
    }

    public function getExercice(): Exercice
    {
        return $this->exercice;
    }

    /** @return Collection<int, Depense> */
    public function getDepenses(): Collection
    {
        return $this->depenses;
    }

    public function getReferenceMarche(): ?string
    {
        return $this->referenceMarche;
    }

    public function setReferenceMarche(?string $referenceMarche): void
    {
        $this->referenceMarche = $referenceMarche;
    }

    public function getNature(): NatureEngagement
    {
        return $this->nature;
    }

    public function getMontant(): Montant
    {
        return $this->montant;
    }

    public function getDateEngagement(): \DateTimeImmutable
    {
        return $this->dateEngagement;
    }

    public function getStatut(): StatutEcriture
    {
        return $this->statut;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): void
    {
        $this->observations = $observations;
    }

    public function getCreePar(): Utilisateur
    {
        return $this->creePar;
    }

    public function getValidePar(): ?Utilisateur
    {
        return $this->validePar;
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function valider(Utilisateur $validePar): void
    {
        $this->statut = StatutEcriture::VALIDE;
        $this->validePar = $validePar;
        $this->valideLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function annuler(): void
    {
        $this->statut = StatutEcriture::ANNULE;
    }
}
