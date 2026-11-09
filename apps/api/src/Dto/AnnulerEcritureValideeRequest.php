<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /engagements|depenses/{uuid}/annuler-validee : sans motif, pas d'annulation. */
final class AnnulerEcritureValideeRequest
{
    #[Assert\NotBlank(message: "Le motif de l'annulation est requis.", normalizer: 'trim')]
    #[Assert\Length(max: 1000, maxMessage: 'Le motif tient en 1000 caractères au plus.')]
    public string $motif = '';
}
