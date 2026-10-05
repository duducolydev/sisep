<?php

declare(strict_types=1);

namespace App\Enum;

enum NatureEngagement: string
{
    case TRAVAUX = 'travaux';
    case FOURNITURES = 'fournitures';
    case PRESTATION_INTELLECTUELLE = 'prestation_intellectuelle';
    case FONCTIONNEMENT = 'fonctionnement';
}
