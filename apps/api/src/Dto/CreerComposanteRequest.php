<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerComposanteRequest
{
    #[Assert\NotBlank(message: 'Le code est requis.')]
    #[Assert\Length(max: 10)]
    public string $code = '';

    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 255)]
    public string $libelle = '';

    public ?string $description = null;

    /** uuid d'une Structure, résolu dans le contrôleur. */
    public ?string $structurePilote = null;

    public int $ordre = 0;
}
