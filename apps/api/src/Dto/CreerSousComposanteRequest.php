<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerSousComposanteRequest
{
    #[Assert\NotBlank(message: 'La composante est requise.')]
    public string $composante = '';

    #[Assert\NotBlank(message: 'Le code est requis.')]
    #[Assert\Length(max: 10)]
    public string $code = '';

    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 255)]
    public string $libelle = '';

    public ?string $description = null;

    public ?string $structurePilote = null;

    public int $ordre = 0;
}
