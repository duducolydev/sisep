<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RealisationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Historique des constats d'avancement. `sous_activite.avancement` est la
 * valeur courante, tenue à jour par un listener Doctrine à l'insertion
 * d'une Realisation (§ 4.6) — garder les deux : l'historique alimente la
 * courbe d'avancement, la valeur courante évite une agrégation à l'affichage.
 */
#[ORM\Entity(repositoryClass: RealisationRepository::class)]
#[ORM\Table(name: 'realisations')]
#[ORM\Index(name: 'idx_realisation_sous_activite_date', columns: ['sous_activite_id', 'date_constat'])]
class Realisation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: SousActivite::class, inversedBy: 'realisations')]
    #[ORM\JoinColumn(nullable: false)]
    private SousActivite $sousActivite;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $avancement;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateConstat;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $creePar;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $creeLe;

    public function __construct(SousActivite $sousActivite, int $avancement, \DateTimeImmutable $dateConstat, Utilisateur $creePar)
    {
        $this->uuid = Uuid::v4();
        $this->sousActivite = $sousActivite;
        $this->avancement = $avancement;
        $this->dateConstat = $dateConstat;
        $this->creePar = $creePar;
        $this->creeLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getSousActivite(): SousActivite
    {
        return $this->sousActivite;
    }

    public function getAvancement(): int
    {
        return $this->avancement;
    }

    public function getDateConstat(): \DateTimeImmutable
    {
        return $this->dateConstat;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): void
    {
        $this->commentaire = $commentaire;
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
