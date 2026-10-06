<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VisibilitePieceJointe;
use App\Repository\PieceJointeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PieceJointeRepository::class)]
#[ORM\Table(name: 'pieces_jointes')]
#[ORM\Index(name: 'idx_piece_jointe_entite', columns: ['entite_type', 'entite_id'])]
class PieceJointe
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 40)]
    private string $entiteType;

    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private int $entiteId;

    #[ORM\Column(length: 255)]
    private string $nomFichier;

    /**
     * Intitulé donné par la personne qui dépose le document, distinct du nom
     * de fichier : « Rapport d'avancement T2 » se cherche, « doc_v3_final.pdf »
     * non. Nullable, et de fait renseigné seulement par la bibliothèque
     * documentaire — un justificatif joint à une étape ou à une valeur
     * d'indicateur se désigne par ce qu'il accompagne.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $libelle = null;

    #[ORM\Column(length: 500)]
    private string $chemin;

    #[ORM\Column(length: 120)]
    private string $mime;

    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private int $tailleOctets;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $creePar;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(length: 30, enumType: VisibilitePieceJointe::class)]
    private VisibilitePieceJointe $visibilite = VisibilitePieceJointe::TOUS;

    /** @var Collection<int, Utilisateur> */
    #[ORM\ManyToMany(targetEntity: Utilisateur::class)]
    #[ORM\JoinTable(name: 'pieces_jointes_visibilite_agents')]
    private Collection $agentsVisibilite;

    public function __construct(
        string $entiteType,
        int $entiteId,
        string $nomFichier,
        string $chemin,
        string $mime,
        int $tailleOctets,
        Utilisateur $creePar,
    ) {
        $this->uuid = Uuid::v4();
        $this->entiteType = $entiteType;
        $this->entiteId = $entiteId;
        $this->nomFichier = $nomFichier;
        $this->chemin = $chemin;
        $this->mime = $mime;
        $this->tailleOctets = $tailleOctets;
        $this->creePar = $creePar;
        $this->creeLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->agentsVisibilite = new ArrayCollection();
    }

    /** @param list<Utilisateur> $agents */
    public function definirVisibilite(VisibilitePieceJointe $visibilite, array $agents = []): void
    {
        $this->visibilite = $visibilite;
        $this->agentsVisibilite = new ArrayCollection(VisibilitePieceJointe::AGENTS_SPECIFIQUES === $visibilite ? $agents : []);
    }

    public function getVisibilite(): VisibilitePieceJointe
    {
        return $this->visibilite;
    }

    /** @return Collection<int, Utilisateur> */
    public function getAgentsVisibilite(): Collection
    {
        return $this->agentsVisibilite;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getEntiteType(): string
    {
        return $this->entiteType;
    }

    public function getEntiteId(): int
    {
        return $this->entiteId;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getNomFichier(): string
    {
        return $this->nomFichier;
    }

    public function getChemin(): string
    {
        return $this->chemin;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getTailleOctets(): int
    {
        return $this->tailleOctets;
    }

    public function getCreePar(): Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
