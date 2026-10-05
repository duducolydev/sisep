<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerActiviteRequest
{
    #[Assert\NotBlank(message: 'La sous-composante est requise.')]
    public string $sousComposante = '';

    #[Assert\NotBlank(message: 'Le code est requis.')]
    #[Assert\Length(max: 20)]
    public string $code = '';

    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 255)]
    public string $libelle = '';

    public ?string $description = null;

    #[Assert\NotBlank(message: 'La structure responsable est requise.')]
    public string $structureResponsable = '';

    public ?string $dateDebut = null;

    public ?string $dateFinPrevue = null;

    public int $ordre = 0;
}
