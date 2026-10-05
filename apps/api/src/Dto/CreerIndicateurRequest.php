<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerIndicateurRequest
{
    #[Assert\NotBlank(message: 'Le code est requis.')]
    #[Assert\Length(max: 20)]
    public string $code = '';

    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    public string $libelle = '';

    public ?string $precisionLibelle = null;

    #[Assert\NotBlank(message: 'Le type est requis.')]
    #[Assert\Choice(choices: ['IOD', 'IRI'], message: 'Le type doit être IOD ou IRI.')]
    public string $type = '';

    public ?string $composante = null;

    #[Assert\NotBlank(message: "L'unité est requise.")]
    public string $unite = '';

    #[Assert\NotBlank(message: 'Le sens est requis.')]
    #[Assert\Choice(choices: ['croissant', 'decroissant'])]
    public string $sens = '';

    #[Assert\NotBlank(message: 'La valeur de référence est requise.')]
    public string $valeurReference = '';

    #[Assert\NotBlank(message: 'La date de référence est requise.')]
    public string $dateReference = '';

    /** Objectif intermédiaire (§ 2.4) — optionnel, jamais dérivé de la cible finale. */
    public ?string $valeurIntermediaire = null;

    public ?string $dateIntermediaire = null;

    #[Assert\NotBlank(message: 'La valeur cible est requise.')]
    public string $valeurCible = '';

    #[Assert\NotBlank(message: 'La date cible est requise.')]
    public string $dateCible = '';

    #[Assert\NotBlank(message: 'La fréquence est requise.')]
    #[Assert\Choice(choices: ['mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'])]
    public string $frequence = '';

    #[Assert\NotBlank(message: 'La source est requise.')]
    public string $source = '';

    public ?string $structureResponsable = null;
}
