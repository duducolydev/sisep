<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Enum\Sexe;
use App\Repository\PersonnelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personnel technique de l'UGP, suivi nominativement (§ 10 du CR du
 * 22/09/2026) — à distinguer des enquêteurs, suivis de façon agrégée par
 * opération (cf. EffectifEnqueteurs).
 *
 * Aucune donnée salariale n'est stockée ici : la réunion les a explicitement
 * écartées comme sensibles (§ 14). Le fichier de référence de l'UGP est un
 * état de paie, mais seules l'identité, le profil et les dates de contrat en
 * sont reprises.
 *
 * `profil` et `fonction` sont deux axes distincts parce que le tableau de
 * bord les croise séparément : le profil est le diplôme ou le corps (ISE,
 * ITS, AS), la fonction est le poste occupé.
 */
#[ORM\Entity(repositoryClass: PersonnelRepository::class)]
#[ORM\Table(name: 'personnels')]
class Personnel
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(length: 120)]
    private string $prenom;

    #[ORM\Column(length: 1, enumType: Sexe::class)]
    private Sexe $sexe;

    /** Poste occupé, tel qu'il figure au fichier de l'UGP. */
    #[ORM\Column(length: 160)]
    private string $fonction;

    /** Diplôme ou corps (ISE, ITS, AS…) — axe de lecture du tableau de bord RH. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $profil = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $specialite = null;

    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Structure $structure = null;

    /** Opération de rattachement : au PHASAOC, une opération est une activité du PTBA. */
    #[ORM\ManyToOne(targetEntity: Activite::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Activite $activite = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $telephone = null;

    /** @var Collection<int, Contrat> */
    #[ORM\OneToMany(targetEntity: Contrat::class, mappedBy: 'personnel', cascade: ['persist'])]
    #[ORM\OrderBy(['dateDebut' => 'DESC'])]
    private Collection $contrats;

    public function __construct(string $nom, string $prenom, Sexe $sexe, string $fonction)
    {
        $this->initialiserUuid();
        $this->nom = $nom;
        $this->prenom = $prenom;
        $this->sexe = $sexe;
        $this->fonction = $fonction;
        $this->contrats = new ArrayCollection();
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
        return $this->prenom.' '.$this->nom;
    }

    public function getSexe(): Sexe
    {
        return $this->sexe;
    }

    public function setSexe(Sexe $sexe): void
    {
        $this->sexe = $sexe;
    }

    public function getFonction(): string
    {
        return $this->fonction;
    }

    public function setFonction(string $fonction): void
    {
        $this->fonction = $fonction;
    }

    public function getProfil(): ?string
    {
        return $this->profil;
    }

    public function setProfil(?string $profil): void
    {
        $this->profil = $profil;
    }

    public function getSpecialite(): ?string
    {
        return $this->specialite;
    }

    public function setSpecialite(?string $specialite): void
    {
        $this->specialite = $specialite;
    }

    public function getStructure(): ?Structure
    {
        return $this->structure;
    }

    public function setStructure(?Structure $structure): void
    {
        $this->structure = $structure;
    }

    public function getActivite(): ?Activite
    {
        return $this->activite;
    }

    public function setActivite(?Activite $activite): void
    {
        $this->activite = $activite;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): void
    {
        $this->telephone = $telephone;
    }

    /** @return Collection<int, Contrat> */
    public function getContrats(): Collection
    {
        return $this->contrats;
    }

    /** @return Collection<int, Contrat> */
    public function getContratsActifs(): Collection
    {
        return $this->contrats->filter(static fn (Contrat $c) => !$c->estSupprime());
    }

    /**
     * Contrat courant : le plus récemment commencé parmi ceux qui restent.
     * Un renouvellement étant un nouveau contrat (§ 10 du CR), c'est lui qui
     * porte l'échéance à surveiller.
     */
    public function getContratCourant(): ?Contrat
    {
        $contrats = array_values($this->getContratsActifs()->toArray());
        if ([] === $contrats) {
            return null;
        }

        usort($contrats, static fn (Contrat $a, Contrat $b) => $b->getDateDebut() <=> $a->getDateDebut());

        return $contrats[0];
    }

    /** Réservé au constructeur de Contrat (côté propriétaire) — garde les deux côtés cohérents avant même un flush. */
    public function ajouterContrat(Contrat $contrat): void
    {
        if (!$this->contrats->contains($contrat)) {
            $this->contrats->add($contrat);
        }
    }
}
