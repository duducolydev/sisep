<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreerIndicateurRequest
{
    #[Assert\NotBlank(message: 'Le code est requis.')]
    #[Assert\Length(max: 20, maxMessage: 'Le code ne doit pas dépasser {{ limit }} caractères.')]
    public string $code = '';

    // Longueurs alignées sur les colonnes de `indicateurs` : au-delà,
    // MySQL refusait l'INSERT (erreur 1406) et l'API répondait 500.
    #[Assert\NotBlank(message: 'Le libellé est requis.')]
    #[Assert\Length(max: 255, maxMessage: 'Le libellé ne doit pas dépasser {{ limit }} caractères.')]
    public string $libelle = '';

    #[Assert\Length(max: 255, maxMessage: 'La précision ne doit pas dépasser {{ limit }} caractères.')]
    public ?string $precisionLibelle = null;

    #[Assert\NotBlank(message: 'Le type est requis.')]
    #[Assert\Choice(choices: ['IOD', 'IRI'], message: 'Le type doit être IOD ou IRI.')]
    public string $type = '';

    public ?string $composante = null;

    #[Assert\NotBlank(message: "L'unité est requise.")]
    #[Assert\Length(max: 40, maxMessage: "L'unité ne doit pas dépasser {{ limit }} caractères.")]
    public string $unite = '';

    #[Assert\NotBlank(message: 'Le sens est requis.')]
    #[Assert\Choice(choices: ['croissant', 'decroissant'])]
    public string $sens = '';

    #[Assert\NotBlank(message: 'La valeur de référence est requise.')]
    public string $valeurReference = '';

    #[Assert\NotBlank(message: 'La date de référence est requise.')]
    #[Assert\Date(message: 'La date de référence est invalide.')]
    public string $dateReference = '';

    /** Objectif intermédiaire (§ 2.4) — optionnel, jamais dérivé de la cible finale. */
    public ?string $valeurIntermediaire = null;

    #[Assert\Date(message: 'La date intermédiaire est invalide.')]
    public ?string $dateIntermediaire = null;

    #[Assert\NotBlank(message: 'La valeur cible est requise.')]
    public string $valeurCible = '';

    #[Assert\NotBlank(message: 'La date cible est requise.')]
    #[Assert\Date(message: 'La date cible est invalide.')]
    public string $dateCible = '';

    #[Assert\NotBlank(message: 'La fréquence est requise.')]
    #[Assert\Choice(choices: ['mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'])]
    public string $frequence = '';

    #[Assert\NotBlank(message: 'La source est requise.')]
    #[Assert\Length(max: 160, maxMessage: 'La source ne doit pas dépasser {{ limit }} caractères.')]
    public string $source = '';

    public ?string $structureResponsable = null;
}
