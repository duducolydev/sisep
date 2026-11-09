<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /dotations/{uuid}/reviser : le motif est obligatoire, c'est lui qui rend la révision contrôlable. */
final class ReviserDotationRequest
{
    #[Assert\NotNull(message: 'Le montant est requis.')]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    public ?int $montant = null;

    #[Assert\NotBlank(message: 'Le motif de la révision est requis.')]
    #[Assert\Length(max: 1000, maxMessage: 'Le motif tient en 1000 caractères au plus.')]
    public string $motif = '';

    #[Assert\PositiveOrZero(message: 'Le montant de référence du COPIL ne peut pas être négatif.')]
    public ?int $montantValideCopil = null;
}
