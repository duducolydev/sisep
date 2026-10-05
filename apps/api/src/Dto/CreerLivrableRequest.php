<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /etapes/:uuid/livrables { libelle, unite?, quantiteAttendue } — § 7 du CR : les livrables doivent pouvoir être quantifiés. */
final class CreerLivrableRequest
{
    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 255)]
    public string $libelle = '';

    #[Assert\Length(max: 60)]
    public ?string $unite = null;

    #[Assert\NotBlank(message: 'La quantité attendue est requise.')]
    public string $quantiteAttendue = '';
}
