<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerDepenseRequest
{
    #[Assert\NotBlank(message: "L'engagement est requis.")]
    public string $engagement = '';

    #[Assert\NotNull(message: 'Le montant est requis.')]
    #[Assert\Positive(message: 'Le montant doit être positif.')]
    public ?int $montant = null;

    #[Assert\NotBlank(message: 'La date de dépense est requise.')]
    public string $dateDepense = '';

    public ?string $referencePaiement = null;
}
