<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH /etapes/:uuid { avancement, valeurActuelle?, observation?, dateMiseAJour }
 * — les quatre champs du § 6 du CR voyagent ensemble, la date comprise :
 * c'est elle qui permettra de repérer les étapes sans nouvelle.
 */
final class RenseignerEtapeRequest
{
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Le niveau atteint doit être compris entre 0 et 100.')]
    public int $avancement = 0;

    public ?string $valeurActuelle = null;

    public ?string $observation = null;

    #[Assert\NotBlank(message: 'La date de mise à jour est requise.')]
    public string $dateMiseAJour = '';
}
