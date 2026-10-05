<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /sous-activites/:uuid/realisations — § 6.2 du brief. */
final class SaisirRealisationRequest
{
    #[Assert\NotNull(message: "L'avancement est requis.")]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: "L'avancement doit être compris entre {{ min }} et {{ max }}.")]
    public ?int $avancement = null;

    #[Assert\NotBlank(message: 'La date de constat est requise.')]
    public string $dateConstat = '';

    public ?string $commentaire = null;
}
