<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Enum\StatutExercice;
use App\Repository\ExerciceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'exercice porte la clôture comptable. Une fois clôturé, aucune écriture
 * budgétaire n'est plus acceptée dessus — contrôle appliqué côté API dans
 * DotationService/EngagementService/DepenseService, pas seulement l'IHM
 * (§ 4.1 du brief).
 */
#[ORM\Entity(repositoryClass: ExerciceRepository::class)]
#[ORM\Table(name: 'exercices')]
#[ORM\UniqueConstraint(name: 'uniq_exercice_projet_annee', columns: ['projet_id', 'annee'])]
class Exercice
{
    use IdentifiantsTrait;

    #[ORM\ManyToOne(targetEntity: Projet::class, inversedBy: 'exercices')]
    #[ORM\JoinColumn(nullable: false)]
    private Projet $projet;

    #[ORM\Column(type: 'smallint')]
    private int $annee;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(length: 20, enumType: StatutExercice::class)]
    private StatutExercice $statut = StatutExercice::OUVERT;

    public function __construct(Projet $projet, int $annee, \DateTimeImmutable $dateDebut, \DateTimeImmutable $dateFin)
    {
        $this->initialiserUuid();
        $this->projet = $projet;
        $this->annee = $annee;
        $this->dateDebut = $dateDebut;
        $this->dateFin = $dateFin;
    }

    public function getProjet(): Projet
    {
        return $this->projet;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function getStatut(): StatutExercice
    {
        return $this->statut;
    }

    public function estOuvert(): bool
    {
        return StatutExercice::OUVERT === $this->statut;
    }

    public function cloturer(): void
    {
        $this->statut = StatutExercice::CLOTURE;
    }

    /** Proportion du temps écoulé dans l'exercice — base du calcul de vigilance (§ 5.3). */
    public function tauxAttendu(\DateTimeImmutable $aLaDate): ?float
    {
        $joursTotaux = $this->dateDebut->diff($this->dateFin)->days;
        if ($joursTotaux <= 0) {
            return null;
        }

        $joursEcoules = min(
            max($this->dateDebut->diff($aLaDate)->days, 0),
            $joursTotaux
        );

        return $joursEcoules / $joursTotaux;
    }
}
