<?php

declare(strict_types=1);

namespace App\Enum;

/** Indicateur d'objectif de développement (IOD) ou de résultat intermédiaire (IRI). */
enum TypeIndicateur: string
{
    case IOD = 'IOD';
    case IRI = 'IRI';
}
