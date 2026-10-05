<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /dotations { activite | sousActivite, exercice, montant, q1..q4?,
 * montantValideCopil?, commentaireRevision? } — § 6.2 du brief, alignement
 * PTBA PHASAOC (trimestrialisation, révision COPIL). Exactement l'un de
 * `activite` ou `sousActivite` doit être fourni — validé dans le contrôleur,
 * pas ici, pour renvoyer un message ciblé.
 */
final class CreerDotationRequest
{
    public ?string $activite = null;

    public ?string $sousActivite = null;

    #[Assert\NotNull(message: "L'exercice est requis.")]
    public ?int $exercice = null;

    #[Assert\NotNull(message: 'Le montant est requis.')]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    public ?int $montant = null;

    #[Assert\PositiveOrZero(message: 'Le montant du trimestre 1 ne peut pas être négatif.')]
    public ?int $q1 = null;

    #[Assert\PositiveOrZero(message: 'Le montant du trimestre 2 ne peut pas être négatif.')]
    public ?int $q2 = null;

    #[Assert\PositiveOrZero(message: 'Le montant du trimestre 3 ne peut pas être négatif.')]
    public ?int $q3 = null;

    #[Assert\PositiveOrZero(message: 'Le montant du trimestre 4 ne peut pas être négatif.')]
    public ?int $q4 = null;

    #[Assert\PositiveOrZero(message: 'Le montant validé par le COPIL ne peut pas être négatif.')]
    public ?int $montantValideCopil = null;

    public ?string $commentaireRevision = null;
}
