<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ChangerMotDePasseRequest
{
    #[Assert\NotBlank(message: 'Le mot de passe actuel est requis.')]
    public string $motDePasseActuel = '';

    #[Assert\NotBlank(message: 'Le nouveau mot de passe est requis.')]
    #[Assert\Length(min: 12, minMessage: 'Le nouveau mot de passe doit contenir au moins {{ limit }} caractères.')]
    public string $nouveauMotDePasse = '';
}
