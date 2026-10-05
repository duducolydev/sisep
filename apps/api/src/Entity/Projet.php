<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\ProjetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Multi-projet dès le schéma (§ 0 du brief) : le PHASAOC n'est qu'une ligne
 * de cette table. Aucun sélecteur de projet dans l'IHM en version 1 (§ 11).
 */
#[ORM\Entity(repositoryClass: ProjetRepository::class)]
#[ORM\Table(name: 'projets')]
class Projet
{
    use IdentifiantsTrait;
    use HorodatageTrait;

    #[ORM\Column(length: 20, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(length: 255)]
    private string $bailleur;

    #[ORM\Column(length: 3)]
    private string $devise = 'XOF';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column]
    private bool $actif = true;

    /** @var Collection<int, Exercice> */
    #[ORM\OneToMany(targetEntity: Exercice::class, mappedBy: 'projet')]
    private Collection $exercices;

    public function __construct(string $code, string $libelle, string $bailleur, \DateTimeImmutable $dateDebut, \DateTimeImmutable $dateFin)
    {
        $this->initialiserUuid();
        $this->code = $code;
        $this->libelle = $libelle;
        $this->bailleur = $bailleur;
        $this->dateDebut = $dateDebut;
        $this->dateFin = $dateFin;
        $this->exercices = new ArrayCollection();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getBailleur(): string
    {
        return $this->bailleur;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function estActif(): bool
    {
        return $this->actif;
    }
}
