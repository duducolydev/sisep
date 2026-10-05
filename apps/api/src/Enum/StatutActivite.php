<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut de cycle de vie saisi par l'utilisateur — distinct de la vigilance
 * calculée (retard / risque élevé), jamais stockée (§ 4.2 et § 5.3 du brief).
 */
enum StatutActivite: string
{
    case PLANIFIEE = 'planifiee';
    case EN_COURS = 'en_cours';
    case TERMINEE = 'terminee';
    case SUSPENDUE = 'suspendue';
    case ANNULEE = 'annulee';
}
