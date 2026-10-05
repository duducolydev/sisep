<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerInitiativeActiviteRequest
{
    #[Assert\NotBlank(message: 'Le projet est requis.')]
    public string $initiative = '';

    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 255)]
    public string $libelle = '';

    public ?int $ordre = null;

    public ?string $dateDebut = null;

    public ?string $echeance = null;

    /** @var list<string> */
    public array $responsables = [];
}
