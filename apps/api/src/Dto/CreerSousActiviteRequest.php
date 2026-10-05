<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerSousActiviteRequest
{
    #[Assert\NotBlank(message: "L'activité est requise.")]
    public string $activite = '';

    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 255)]
    public string $libelle = '';

    public ?string $structureResponsable = null;

    public ?string $dateDebut = null;

    public ?string $echeance = null;

    public int $ordre = 0;
}
