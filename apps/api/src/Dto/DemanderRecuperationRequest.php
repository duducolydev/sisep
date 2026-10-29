<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DemanderRecuperationRequest
{
    #[Assert\NotBlank(message: "L'adresse e-mail est requise.")]
    #[Assert\Email(message: 'Adresse e-mail invalide.')]
    #[Assert\Length(max: 180)]
    public string $email = '';
}
