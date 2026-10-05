<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutRapport: string
{
    case ATTENDU = 'attendu';
    case TRANSMIS = 'transmis';
    case VALIDE = 'valide';
    case REJETE = 'rejete';
}
