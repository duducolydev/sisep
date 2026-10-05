<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerNoteInitiativeRequest
{
    #[Assert\Choice(choices: ['initiative_activite', 'tache_initiative'], message: 'entiteType doit valoir initiative_activite ou tache_initiative.')]
    public string $entiteType = '';

    #[Assert\NotBlank(message: "L'entité ciblée est requise.")]
    public string $entiteUuid = '';

    #[Assert\NotBlank(message: 'Le contenu est requis.')]
    public string $contenu = '';
}
