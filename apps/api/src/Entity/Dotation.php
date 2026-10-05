<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\DotationRepository;
use App\ValueObject\Montant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cible activité OU sous-activité (§ 6.2, alignement PTBA PHASAOC du
 * 06/08/2026) : le PTBA réel chiffre certaines lignes au niveau
 * sous-activité (ex. RGPH-5, EHCVM) — jamais les deux à la fois, contrainte
 * `chk_dotation_cible` en base.
 *
 * q1..q4 : répartition trimestrielle du montant annuel, telle que présentée
 * dans le PTBA (colonnes TRIM 1-4) — informative, la somme n'est pas
 * contrainte en base pour tolérer les écarts d'arrondi déjà présents dans le
 * fichier source.
 *
 * montantValideCopil / commentaireRevision : trace la révision budgétaire
 * telle que pratiquée par le PHASAOC (colonnes "Budget total amendé COPIL"
 * et "Commentaires sur les changements" du PTBA) — l'écart n'est jamais
 * stocké, toujours recalculé à l'affichage (§ 5.1).
 */
#[ORM\Entity(repositoryClass: DotationRepository::class)]
#[ORM\Table(name: 'dotations')]
#[ORM\UniqueConstraint(name: 'uniq_dotation_activite_exercice', columns: ['activite_id', 'exercice_id'])]
#[ORM\UniqueConstraint(name: 'uniq_dotation_sous_activite_exercice', columns: ['sous_activite_id', 'exercice_id'])]
class Dotation
{
    use IdentifiantsTrait;
    use HorodatageTrait;

    #[ORM\ManyToOne(targetEntity: Activite::class, inversedBy: 'dotations')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Activite $activite = null;

    #[ORM\ManyToOne(targetEntity: SousActivite::class, inversedBy: 'dotations')]
    #[ORM\JoinColumn(nullable: true)]
    private ?SousActivite $sousActivite = null;

    #[ORM\ManyToOne(targetEntity: Exercice::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Exercice $exercice;

    #[ORM\Column(type: 'montant')]
    private Montant $montant;

    #[ORM\Column(type: 'montant', nullable: true)]
    private ?Montant $q1 = null;

    #[ORM\Column(type: 'montant', nullable: true)]
    private ?Montant $q2 = null;

    #[ORM\Column(type: 'montant', nullable: true)]
    private ?Montant $q3 = null;

    #[ORM\Column(type: 'montant', nullable: true)]
    private ?Montant $q4 = null;

    #[ORM\Column(type: 'montant', nullable: true)]
    private ?Montant $montantValideCopil = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaireRevision = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Utilisateur $creePar;

    public function __construct(Activite|SousActivite $cible, Exercice $exercice, Montant $montant, Utilisateur $creePar)
    {
        $this->initialiserUuid();
        if ($cible instanceof Activite) {
            $this->activite = $cible;
        } else {
            $this->sousActivite = $cible;
        }
        $this->exercice = $exercice;
        $this->montant = $montant;
        $this->creePar = $creePar;
    }

    public function getActivite(): ?Activite
    {
        return $this->activite;
    }

    public function getSousActivite(): ?SousActivite
    {
        return $this->sousActivite;
    }

    /** L'activité ou la sous-activité directement ciblée par cette dotation. */
    public function getCible(): Activite|SousActivite
    {
        return $this->activite ?? $this->sousActivite;
    }

    /** Résout toujours l'activité porteuse, même quand la dotation cible une de ses sous-activités. */
    public function getActiviteParente(): Activite
    {
        return $this->activite ?? $this->sousActivite->getActivite();
    }

    public function getExercice(): Exercice
    {
        return $this->exercice;
    }

    public function getMontant(): Montant
    {
        return $this->montant;
    }

    public function getQ1(): ?Montant
    {
        return $this->q1;
    }

    public function setQ1(?Montant $q1): void
    {
        $this->q1 = $q1;
    }

    public function getQ2(): ?Montant
    {
        return $this->q2;
    }

    public function setQ2(?Montant $q2): void
    {
        $this->q2 = $q2;
    }

    public function getQ3(): ?Montant
    {
        return $this->q3;
    }

    public function setQ3(?Montant $q3): void
    {
        $this->q3 = $q3;
    }

    public function getQ4(): ?Montant
    {
        return $this->q4;
    }

    public function setQ4(?Montant $q4): void
    {
        $this->q4 = $q4;
    }

    public function getMontantValideCopil(): ?Montant
    {
        return $this->montantValideCopil;
    }

    public function setMontantValideCopil(?Montant $montantValideCopil): void
    {
        $this->montantValideCopil = $montantValideCopil;
    }

    public function getCommentaireRevision(): ?string
    {
        return $this->commentaireRevision;
    }

    public function setCommentaireRevision(?string $commentaireRevision): void
    {
        $this->commentaireRevision = $commentaireRevision;
    }

    public function getCreePar(): Utilisateur
    {
        return $this->creePar;
    }
}
