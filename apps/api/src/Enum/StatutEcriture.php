<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Cycle de vie commun aux engagements et aux dépenses (§ 4.3 du brief).
 * Séparer saisir/valider est une exigence de contrôle interne (§ 7.2) :
 * cet enum ne dit rien de qui peut faire quoi, c'est le rôle des permissions.
 */
enum StatutEcriture: string
{
    case BROUILLON = 'brouillon';
    case VALIDE = 'valide';
    case ANNULE = 'annule';
}
