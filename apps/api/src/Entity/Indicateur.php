<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Enum\FrequenceIndicateur;
use App\Enum\SensIndicateur;
use App\Enum\TypeIndicateur;
use App\Repository\IndicateurRepository;
use App\Security\PorteeParStructureInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * DECIMAL et non Montant ici : un indicateur peut valoir 8,5 mois ou 98,2 % (§ 4.4).
 *
 * La portée de indicateurs.saisir (§ 7.2) se juge sur structureResponsable :
 * une structure peut renseigner la valeur périodique des indicateurs dont
 * elle est responsable, sans détenir indicateurs.definir (référentiel
 * méthodologique, resté global — responsable suivi-évaluation). Un
 * indicateur sans structureResponsable ne peut être saisi que par une
 * attribution globale (getStructurePourPortee() renvoie null).
 */
#[ORM\Entity(repositoryClass: IndicateurRepository::class)]
#[ORM\Table(name: 'indicateurs')]
#[ORM\UniqueConstraint(name: 'uniq_indicateur_projet_code', columns: ['projet_id', 'code', 'supprime_le'])]
class Indicateur implements PorteeParStructureInterface
{
    use IdentifiantsTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Projet::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Projet $projet;

    #[ORM\Column(length: 20)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $precisionLibelle = null;

    #[ORM\Column(length: 3, enumType: TypeIndicateur::class)]
    private TypeIndicateur $type;

    #[ORM\ManyToOne(targetEntity: Composante::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Composante $composante = null;

    #[ORM\Column(length: 40)]
    private string $unite;

    #[ORM\Column(length: 15, enumType: SensIndicateur::class)]
    private SensIndicateur $sens;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $valeurReference;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateReference;

    /**
     * Objectif intermédiaire (§ 2.4, alignement tableau de bord réel du
     * PHASAOC) : jalon à mi-parcours, distinct de la cible finale — les deux
     * sont saisis indépendamment, aucun n'est dérivé de l'autre.
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 4, nullable: true)]
    private ?string $valeurIntermediaire = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateIntermediaire = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $valeurCible;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateCible;

    #[ORM\Column(length: 20, enumType: FrequenceIndicateur::class)]
    private FrequenceIndicateur $frequence;

    #[ORM\Column(length: 160)]
    private string $source;

    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Structure $structureResponsable = null;

    #[ORM\Column]
    private bool $actif = true;

    /** @var Collection<int, IndicateurValeur> */
    #[ORM\OneToMany(targetEntity: IndicateurValeur::class, mappedBy: 'indicateur')]
    private Collection $valeurs;

    public function __construct(
        Projet $projet,
        string $code,
        string $libelle,
        TypeIndicateur $type,
        string $unite,
        SensIndicateur $sens,
        string $valeurReference,
        \DateTimeImmutable $dateReference,
        string $valeurCible,
        \DateTimeImmutable $dateCible,
        FrequenceIndicateur $frequence,
        string $source,
    ) {
        $this->initialiserUuid();
        $this->projet = $projet;
        $this->code = $code;
        $this->libelle = $libelle;
        $this->type = $type;
        $this->unite = $unite;
        $this->sens = $sens;
        $this->valeurReference = $valeurReference;
        $this->dateReference = $dateReference;
        $this->valeurCible = $valeurCible;
        $this->dateCible = $dateCible;
        $this->frequence = $frequence;
        $this->source = $source;
        $this->valeurs = new ArrayCollection();
    }

    public function getProjet(): Projet
    {
        return $this->projet;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getPrecisionLibelle(): ?string
    {
        return $this->precisionLibelle;
    }

    public function setPrecisionLibelle(?string $precisionLibelle): void
    {
        $this->precisionLibelle = $precisionLibelle;
    }

    public function getType(): TypeIndicateur
    {
        return $this->type;
    }

    public function getComposante(): ?Composante
    {
        return $this->composante;
    }

    public function setComposante(?Composante $composante): void
    {
        $this->composante = $composante;
    }

    public function getUnite(): string
    {
        return $this->unite;
    }

    public function setUnite(string $unite): void
    {
        $this->unite = $unite;
    }

    public function getSens(): SensIndicateur
    {
        return $this->sens;
    }

    public function getValeurReference(): string
    {
        return $this->valeurReference;
    }

    public function setValeurReference(string $valeurReference): void
    {
        $this->valeurReference = $valeurReference;
    }

    public function getDateReference(): \DateTimeImmutable
    {
        return $this->dateReference;
    }

    public function getValeurIntermediaire(): ?string
    {
        return $this->valeurIntermediaire;
    }

    public function setValeurIntermediaire(?string $valeurIntermediaire): void
    {
        $this->valeurIntermediaire = $valeurIntermediaire;
    }

    public function getDateIntermediaire(): ?\DateTimeImmutable
    {
        return $this->dateIntermediaire;
    }

    public function setDateIntermediaire(?\DateTimeImmutable $dateIntermediaire): void
    {
        $this->dateIntermediaire = $dateIntermediaire;
    }

    public function getValeurCible(): string
    {
        return $this->valeurCible;
    }

    public function setValeurCible(string $valeurCible): void
    {
        $this->valeurCible = $valeurCible;
    }

    public function getDateCible(): \DateTimeImmutable
    {
        return $this->dateCible;
    }

    public function setDateCible(\DateTimeImmutable $dateCible): void
    {
        $this->dateCible = $dateCible;
    }

    public function getFrequence(): FrequenceIndicateur
    {
        return $this->frequence;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getStructureResponsable(): ?Structure
    {
        return $this->structureResponsable;
    }

    public function setStructureResponsable(?Structure $structureResponsable): void
    {
        $this->structureResponsable = $structureResponsable;
    }

    public function getStructurePourPortee(): ?Structure
    {
        return $this->structureResponsable;
    }

    public function estActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
    }

    /** @return Collection<int, IndicateurValeur> */
    public function getValeurs(): Collection
    {
        return $this->valeurs;
    }
}
