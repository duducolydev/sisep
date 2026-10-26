<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Enum\StatutValeurIndicateur;
use App\Repository\IndicateurValeurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndicateurValeurRepository::class)]
#[ORM\Table(name: 'indicateur_valeurs')]
#[ORM\UniqueConstraint(name: 'uniq_indicateur_valeur_periode', columns: ['indicateur_id', 'periode'])]
class IndicateurValeur
{
    use IdentifiantsTrait;

    #[ORM\ManyToOne(targetEntity: Indicateur::class, inversedBy: 'valeurs')]
    #[ORM\JoinColumn(nullable: false)]
    private Indicateur $indicateur;

    /** Premier jour de la période observée. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periode;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $valeur;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(length: 20, enumType: StatutValeurIndicateur::class)]
    private StatutValeurIndicateur $statut = StatutValeurIndicateur::BROUILLON;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $saisiPar;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $validePar = null;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    /** @var Collection<int, IndicateurDesagregation> */
    #[ORM\OneToMany(targetEntity: IndicateurDesagregation::class, mappedBy: 'indicateurValeur')]
    private Collection $desagregations;

    public function __construct(Indicateur $indicateur, \DateTimeImmutable $periode, string $valeur, Utilisateur $saisiPar)
    {
        $this->initialiserUuid();
        $this->indicateur = $indicateur;
        $this->periode = $periode;
        $this->valeur = $valeur;
        $this->saisiPar = $saisiPar;
        $this->desagregations = new ArrayCollection();
    }

    public function getIndicateur(): Indicateur
    {
        return $this->indicateur;
    }

    public function getPeriode(): \DateTimeImmutable
    {
        return $this->periode;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): void
    {
        $this->commentaire = $commentaire;
    }

    public function getStatut(): StatutValeurIndicateur
    {
        return $this->statut;
    }

    public function getSaisiPar(): Utilisateur
    {
        return $this->saisiPar;
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
        $this->statut = StatutValeurIndicateur::VALIDE;
        $this->validePar = $validePar;
        $this->valideLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Resaisie d'une période déjà renseignée (upsert, § décision incrément) :
     * remet le statut à BROUILLON et efface la validation existante — une
     * valeur modifiée est une donnée non encore approuvée, jamais une
     * validation qui persiste silencieusement sur un nombre différent
     * (séparation saisie/validation, CLAUDE.md § 7.2).
     *
     * `saisiPar` devient l'auteur de cette resaisie : c'est lui qui ne peut
     * pas la valider. Sans cela, un validateur qui corrige la valeur d'un
     * sectoriel validerait ensuite son propre chiffre. L'auteur précédent
     * reste dans journal_activite.
     */
    public function ajusterValeur(string $nouvelleValeur, Utilisateur $auteur): void
    {
        $this->valeur = $nouvelleValeur;
        $this->saisiPar = $auteur;
        $this->statut = StatutValeurIndicateur::BROUILLON;
        $this->validePar = null;
        $this->valideLe = null;
    }

    /** @return Collection<int, IndicateurDesagregation> */
    public function getDesagregations(): Collection
    {
        return $this->desagregations;
    }

    /** Réservé au constructeur de IndicateurDesagregation (côté propriétaire) — garde les deux côtés cohérents avant même un flush. */
    public function ajouterDesagregation(IndicateurDesagregation $desagregation): void
    {
        if (!$this->desagregations->contains($desagregation)) {
            $this->desagregations->add($desagregation);
        }
    }
}
