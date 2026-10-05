<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /gabarits-etapes { libelle, description?, etapes[] } — les étapes
 * arrivent comme une simple liste de libellés ordonnée : leur ordre dans le
 * tableau fait foi, c'est la seule chose qui les distingue.
 */
final class CreerGabaritEtapesRequest
{
    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 160)]
    public string $libelle = '';

    public ?string $description = null;

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'Un gabarit doit comporter au moins une étape.')]
    #[Assert\All([new Assert\NotBlank(message: 'Un libellé d\'étape ne peut pas être vide.'), new Assert\Length(max: 160)])]
    public array $etapes = [];
}
