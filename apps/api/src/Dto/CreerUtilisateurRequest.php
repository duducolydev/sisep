<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerUtilisateurRequest
{
    #[Assert\NotBlank(message: "L'email est requis.")]
    #[Assert\Email(message: 'Email invalide.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Le nom est requis.')]
    #[Assert\Length(max: 100)]
    public string $nom = '';

    #[Assert\NotBlank(message: 'Le prénom est requis.')]
    #[Assert\Length(max: 100)]
    public string $prenom = '';

    #[Assert\Length(max: 120)]
    public ?string $fonction = null;

    public ?string $structure = null;

    /** @var list<mixed> validé/normalisé à la main dans le contrôleur — la forme n'est pas garantie par le désérialiseur. */
    public array $roles = [];
}
