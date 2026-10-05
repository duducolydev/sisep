<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerPointFocalRequest
{
    #[Assert\NotBlank(message: 'La structure est requise.')]
    public string $structure = '';

    #[Assert\NotBlank(message: 'Le nom complet est requis.')]
    #[Assert\Length(max: 160)]
    public string $nomComplet = '';

    #[Assert\NotBlank(message: 'La fonction est requise.')]
    #[Assert\Length(max: 160)]
    public string $fonction = '';

    public ?string $telephone = null;
    public ?string $email = null;
    public bool $principal = false;
    public ?string $dateDesignation = null;
}
