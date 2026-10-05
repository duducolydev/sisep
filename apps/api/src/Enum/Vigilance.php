<?php

declare(strict_types=1);

namespace App\Enum;

/** Calculée à la demande, jamais stockée (§ 5.3 du brief). */
enum Vigilance: string
{
    case NORMAL = 'normal';
    case EN_RETARD = 'en_retard';
    case RISQUE_ELEVE = 'risque_eleve';
}
