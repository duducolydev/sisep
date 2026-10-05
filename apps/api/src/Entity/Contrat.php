<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\ContratRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Période contractuelle d'un agent (§ 10 du CR du 22/09/2026).
 *
 * Un renouvellement crée une nouvelle ligne, il ne prolonge jamais la
 * précédente : la réunion l'a explicitement demandé, et c'est ce qui permet
 * de reconstituer l'historique des engagements du projet.
 *
 * Aucun montant : les informations salariales sont écartées (§ 14).
 */
#[ORM\Entity(repositoryClass: ContratRepository::class)]
#[ORM\Table(name: 'contrats')]
class Contrat
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: Personnel::class, inversedBy: 'contrats')]
    #[ORM\JoinColumn(nullable: false)]
    private Personnel $personnel;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateDebut;

    /** Nullable : un contrat sans terme fixé n'a pas d'échéance à surveiller. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    /**
     * Au PHASAOC, tout le personnel technique est sous contrat de prestation
     * (confirmé par l'UGP) : le champ vaut donc cette valeur par défaut et
     * n'a pas à être ressaisi. Il reste une chaîne libre plutôt qu'une
     * énumération pour ne pas imposer une migration le jour où un autre type
     * apparaîtra.
     */
    public const TYPE_PAR_DEFAUT = 'Contrat de prestation';

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $type = self::TYPE_PAR_DEFAUT;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observation = null;

    public function __construct(Personnel $personnel, \DateTimeImmutable $dateDebut)
    {
        $this->initialiserUuid();
        $this->personnel = $personnel;
        $this->dateDebut = $dateDebut;
        $personnel->ajouterContrat($this);
    }

    public function getPersonnel(): Personnel
    {
        return $this->personnel;
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): void
    {
        $this->dateDebut = $dateDebut;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): void
    {
        $this->dateFin = $dateFin;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): void
    {
        $this->observation = $observation;
    }

    /** Durée en jours, calculée et jamais stockée. Null tant qu'aucune échéance n'est fixée. */
    public function dureeEnJours(): ?int
    {
        $duree = $this->dateFin?->diff($this->dateDebut);

        return null === $duree ? null : (int) $duree->days;
    }

    /**
     * Jours restants avant l'échéance — négatif si elle est passée, null si
     * le contrat n'en a pas. C'est ce que liront les alertes de fin de
     * contrat du chantier « alertes planifiées ».
     */
    public function joursAvantEcheance(\DateTimeImmutable $maintenant): ?int
    {
        if (null === $this->dateFin) {
            return null;
        }

        return (int) $maintenant->setTime(0, 0)->diff($this->dateFin->setTime(0, 0))->format('%r%a');
    }
}
