<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ParametreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètres éditables depuis l'administration — notamment les seuils de
 * vigilance du § 5.3, que la coordination voudra ajuster sans déploiement.
 */
#[ORM\Entity(repositoryClass: ParametreRepository::class)]
#[ORM\Table(name: 'parametres')]
class Parametre
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $code;

    #[ORM\Column(length: 40)]
    private string $valeur;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $modifiePar = null;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $modifieLe = null;

    public function __construct(string $code, string $valeur, ?string $description = null)
    {
        $this->code = $code;
        $this->valeur = $valeur;
        $this->description = $description;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function definirValeur(string $valeur, ?Utilisateur $modifiePar): void
    {
        $this->valeur = $valeur;
        $this->modifiePar = $modifiePar;
        $this->modifieLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }
}
