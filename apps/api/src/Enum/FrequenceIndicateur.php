<?php

declare(strict_types=1);

namespace App\Enum;

enum FrequenceIndicateur: string
{
    case MENSUELLE = 'mensuelle';
    case TRIMESTRIELLE = 'trimestrielle';
    case SEMESTRIELLE = 'semestrielle';
    case ANNUELLE = 'annuelle';
}
