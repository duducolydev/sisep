<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerTacheInitiativeRequest
{
    #[Assert\NotBlank(message: "L'activité est requise.")]
    public string $initiativeActivite = '';

    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 255)]
    public string $libelle = '';

    public ?int $ordre = null;

    public ?string $responsable = null;
}
