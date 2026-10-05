<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerStructureRequest
{
    #[Assert\NotBlank(message: 'Le sigle est requis.')]
    #[Assert\Length(max: 20)]
    public string $sigle = '';

    #[Assert\NotBlank(message: 'La dénomination est requise.')]
    #[Assert\Length(max: 255)]
    public string $denomination = '';

    #[Assert\NotBlank(message: 'Le type est requis.')]
    public string $type = '';

    /** Obligatoire : sert de destinataire au système d'alertes par courriel. */
    #[Assert\NotBlank(message: "L'email est requis pour recevoir les alertes.")]
    #[Assert\Email(message: 'Email invalide.')]
    public string $email = '';

    public ?string $telephone = null;
    public ?string $adresse = null;
}
