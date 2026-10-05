<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutExercice: string
{
    case OUVERT = 'ouvert';
    case CLOTURE = 'cloture';
}
