<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /engagements { activite | sousActivite, ... } — exactement l'un des
 * deux doit être fourni, validé dans le contrôleur (alignement PTBA PHASAOC,
 * § 6.2).
 */
final class CreerEngagementRequest
{
    public ?string $activite = null;

    public ?string $sousActivite = null;

    #[Assert\NotNull(message: "L'exercice est requis.")]
    public ?int $exercice = null;

    public ?string $referenceMarche = null;

    #[Assert\NotBlank(message: 'La nature est requise.')]
    public string $nature = '';

    #[Assert\NotNull(message: 'Le montant est requis.')]
    #[Assert\Positive(message: 'Le montant doit être positif.')]
    public ?int $montant = null;

    #[Assert\NotBlank(message: "La date d'engagement est requise.")]
    public string $dateEngagement = '';

    public ?string $observations = null;
}
