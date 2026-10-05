<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut calculé (jamais saisi) d'une activité ou tâche du module Projets —
 * fusionne ce qui était auparavant un statut saisi + une vigilance calculée
 * séparément. Propre au module Projets : sans rapport avec
 * App\Enum\StatutActivite (module PTBA/Composantes, saisi, non touché ici).
 */
enum StatutInitiativeActivite: string
{
    case PLANIFIEE = 'planifiee';
    case EN_COURS = 'en_cours';
    case A_RISQUE = 'a_risque';
    case EN_RETARD = 'en_retard';
    case TERMINEE = 'terminee';
}
