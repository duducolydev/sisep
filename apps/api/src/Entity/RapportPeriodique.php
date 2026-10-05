<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Enum\PeriodeTrimestre;
use App\Enum\StatutRapport;
use App\Repository\RapportPeriodiqueRepository;
use App\Security\PorteeParStructureInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * "En retard" n'est pas un statut stocké : comme la vigilance des activités
 * (§ 5.3), c'est `attendu` dont l'échéance est dépassée, calculé à la
 * demande (§ 4.1 CLAUDE.md — grandeurs dérivées jamais stockées). Le brief
 * (§ 5.5) parle d'une "tâche planifiée qui bascule en retard" ; on obtient
 * le même résultat sans écriture ni colonne supplémentaire.
 */
#[ORM\Entity(repositoryClass: RapportPeriodiqueRepository::class)]
#[ORM\Table(name: 'rapports_periodiques')]
#[ORM\UniqueConstraint(name: 'uniq_rapport_structure_exercice_periode', columns: ['structure_id', 'exercice_id', 'periode'])]
class RapportPeriodique implements PorteeParStructureInterface
{
    use IdentifiantsTrait;

    #[ORM\ManyToOne(targetEntity: Structure::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Structure $structure;

    #[ORM\ManyToOne(targetEntity: Exercice::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Exercice $exercice;

    #[ORM\Column(length: 2, enumType: PeriodeTrimestre::class)]
    private PeriodeTrimestre $periode;

    #[ORM\Column(length: 20, enumType: StatutRapport::class)]
    private StatutRapport $statut = StatutRapport::ATTENDU;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateEcheance;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $dateTransmission = null;

    #[ORM\ManyToOne(targetEntity: PieceJointe::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PieceJointe $pieceJointe = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $derniereRelanceLe = null;

    public function __construct(Structure $structure, Exercice $exercice, PeriodeTrimestre $periode, \DateTimeImmutable $dateEcheance)
    {
        $this->initialiserUuid();
        $this->structure = $structure;
        $this->exercice = $exercice;
        $this->periode = $periode;
        $this->dateEcheance = $dateEcheance;
    }

    public function getStructure(): Structure
    {
        return $this->structure;
    }

    public function getStructurePourPortee(): ?Structure
    {
        return $this->structure;
    }

    public function estEnRetard(\DateTimeImmutable $maintenant): bool
    {
        return StatutRapport::ATTENDU === $this->statut && $maintenant > $this->dateEcheance;
    }

    public function getExercice(): Exercice
    {
        return $this->exercice;
    }

    public function getPeriode(): PeriodeTrimestre
    {
        return $this->periode;
    }

    public function getStatut(): StatutRapport
    {
        return $this->statut;
    }

    public function getDateEcheance(): \DateTimeImmutable
    {
        return $this->dateEcheance;
    }

    public function getDateTransmission(): ?\DateTimeImmutable
    {
        return $this->dateTransmission;
    }

    public function getPieceJointe(): ?PieceJointe
    {
        return $this->pieceJointe;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): void
    {
        $this->commentaire = $commentaire;
    }

    public function transmettre(PieceJointe $pieceJointe): void
    {
        $this->statut = StatutRapport::TRANSMIS;
        $this->pieceJointe = $pieceJointe;
        $this->dateTransmission = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function valider(): void
    {
        $this->statut = StatutRapport::VALIDE;
    }

    public function rejeter(): void
    {
        $this->statut = StatutRapport::REJETE;
    }

    public function getDerniereRelanceLe(): ?\DateTimeImmutable
    {
        return $this->derniereRelanceLe;
    }

    /**
     * N'horodate que la relance : l'envoi du courriel est du ressort de
     * RapportController::relancer(), qui connaît les destinataires (points
     * focaux de la structure) — l'entité, elle, reste sans dépendance
     * externe. L'horodatage vaut trace même si aucun courriel n'a pu partir
     * (adresse manquante, SMTP indisponible).
     */
    public function relancer(\DateTimeImmutable $maintenant): void
    {
        $this->derniereRelanceLe = $maintenant;
    }
}
