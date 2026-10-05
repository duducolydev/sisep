<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutValeurIndicateur: string
{
    case BROUILLON = 'brouillon';
    case VALIDE = 'valide';
}
