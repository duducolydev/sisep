<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndicateurDesagregationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndicateurDesagregationRepository::class)]
#[ORM\Table(name: 'indicateur_desagregations')]
class IndicateurDesagregation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: IndicateurValeur::class, inversedBy: 'desagregations')]
    #[ORM\JoinColumn(nullable: false)]
    private IndicateurValeur $indicateurValeur;

    /** ex. 'sexe', 'region'. */
    #[ORM\Column(length: 40)]
    private string $dimension;

    /** ex. 'femmes'. */
    #[ORM\Column(length: 80)]
    private string $modalite;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $valeur;

    public function __construct(IndicateurValeur $indicateurValeur, string $dimension, string $modalite, string $valeur)
    {
        $this->indicateurValeur = $indicateurValeur;
        $this->dimension = $dimension;
        $this->modalite = $modalite;
        $this->valeur = $valeur;
        $indicateurValeur->ajouterDesagregation($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIndicateurValeur(): IndicateurValeur
    {
        return $this->indicateurValeur;
    }

    public function getDimension(): string
    {
        return $this->dimension;
    }

    public function getModalite(): string
    {
        return $this->modalite;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    /** Resaisie de la valeur dont elle est la ventilation — elle repasse en brouillon avec elle. */
    public function corriger(string $valeur): void
    {
        $this->valeur = $valeur;
    }
}
