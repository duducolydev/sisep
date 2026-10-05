<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerInitiativeRequest
{
    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 160)]
    public string $libelle = '';

    public ?string $description = null;

    /** Au plus un des deux — validé dans le contrôleur, pas ici (dépend de la résolution des uuid). */
    public ?string $composante = null;

    public ?string $sousComposante = null;
}
