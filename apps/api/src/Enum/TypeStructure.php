<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeStructure: string
{
    case CHEF_DE_FILE = 'chef_de_file';
    case DIRECTION_ANSD = 'direction_ansd';
    case CELLULE_ANSD = 'cellule_ansd';
    case PARTENAIRE_NATIONAL = 'partenaire_national';
    case PARTENAIRE_TECHNIQUE = 'partenaire_technique';
    case PARTENAIRE_FORMATION = 'partenaire_formation';
    case BAILLEUR = 'bailleur';
}
