<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\EffectifEnqueteursRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Effectif d'enquêteurs mobilisés pour une opération, suivi de façon agrégée
 * (§ 10 du CR du 22/09/2026) : la réunion a écarté le suivi individuel
 * systématique de ce personnel ponctuel, au profit d'un comptage par
 * opération avec désagrégation par sexe.
 *
 * L'effectif total n'est pas stocké : c'est la somme des femmes et des
 * hommes, donc une grandeur dérivée (§ 5.1). Deux colonnes ne peuvent pas
 * diverger d'un total qui n'existe pas.
 */
#[ORM\Entity(repositoryClass: EffectifEnqueteursRepository::class)]
#[ORM\Table(name: 'effectifs_enqueteurs')]
class EffectifEnqueteurs
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    /** L'opération : au PHASAOC, c'est une activité du PTBA. */
    #[ORM\ManyToOne(targetEntity: Activite::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Activite $activite;

    /** Vague de collecte, quand une même opération en compte plusieurs (ex. « Collecte principale »). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $libelle = null;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $annee;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $nombreFemmes = 0;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $nombreHommes = 0;

    /**
     * Période de mobilisation des enquêteurs, distincte de l'année : une
     * collecte peut tenir en trois semaines comme chevaucher deux exercices.
     * Nullable parce que l'UGP renseigne l'effectif dès la programmation,
     * souvent avant d'avoir arrêté le calendrier (demande du 05/10/2026).
     */
    #[ORM\Column(name: 'date_debut', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(name: 'date_fin', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    public function __construct(Activite $activite, int $annee)
    {
        $this->initialiserUuid();
        $this->activite = $activite;
        $this->annee = $annee;
    }

    public function getActivite(): Activite
    {
        return $this->activite;
    }

    public function setActivite(Activite $activite): void
    {
        $this->activite = $activite;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function setAnnee(int $annee): void
    {
        $this->annee = $annee;
    }

    public function getNombreFemmes(): int
    {
        return $this->nombreFemmes;
    }

    public function getNombreHommes(): int
    {
        return $this->nombreHommes;
    }

    public function definirEffectifs(int $femmes, int $hommes): void
    {
        $this->nombreFemmes = max(0, $femmes);
        $this->nombreHommes = max(0, $hommes);
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    /**
     * Les deux bornes bougent ensemble : une fin seule n'a pas de sens, et
     * c'est ici que l'ordre des dates se vérifie une fois pour toutes.
     *
     * @throws \InvalidArgumentException si la fin précède le début
     */
    public function definirPeriode(?\DateTimeImmutable $debut, ?\DateTimeImmutable $fin): void
    {
        if (null !== $debut && null !== $fin && $fin < $debut) {
            throw new \InvalidArgumentException('La date de fin ne peut pas précéder la date de début.');
        }

        $this->dateDebut = $debut;
        $this->dateFin = $fin;
    }

    /** Durée de mobilisation en jours, calculée et jamais stockée. Null tant que les deux bornes ne sont pas connues. */
    public function dureeEnJours(): ?int
    {
        if (null === $this->dateDebut || null === $this->dateFin) {
            return null;
        }

        return (int) $this->dateDebut->diff($this->dateFin)->days;
    }

    public function effectifTotal(): int
    {
        return $this->nombreFemmes + $this->nombreHommes;
    }

    /** Part des femmes dans l'effectif — null si aucun enquêteur n'est encore renseigné (§ 5.1, division par zéro). */
    public function partFemmes(): ?float
    {
        $total = $this->effectifTotal();

        return 0 === $total ? null : $this->nombreFemmes / $total;
    }
}
