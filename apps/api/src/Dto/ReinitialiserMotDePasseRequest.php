<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Mêmes exigences que ChangerMotDePasseRequest pour le nouveau mot de passe. */
final class ReinitialiserMotDePasseRequest
{
    #[Assert\NotBlank(message: 'Le lien de récupération est incomplet.')]
    public string $jeton = '';

    #[Assert\NotBlank(message: 'Le nouveau mot de passe est requis.')]
    #[Assert\Length(min: 12, minMessage: 'Le nouveau mot de passe doit contenir au moins {{ limit }} caractères.')]
    public string $nouveauMotDePasse = '';
}
