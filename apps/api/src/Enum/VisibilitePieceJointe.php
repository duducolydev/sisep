<?php

declare(strict_types=1);

namespace App\Enum;

enum VisibilitePieceJointe: string
{
    case TOUS = 'tous';
    case EQUIPE_PHASAOC = 'equipe_phasaoc';
    case SECTORIELS = 'sectoriels';
    case AGENTS_SPECIFIQUES = 'agents_specifiques';
}
