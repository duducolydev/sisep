<?php

declare(strict_types=1);

namespace App\Enum;

/** Reprend les valeurs du fichier de référence de l'UGP (colonne SEXE : M / F) — la désagrégation par sexe est demandée au § 10 du CR du 22/09/2026. */
enum Sexe: string
{
    case MASCULIN = 'M';
    case FEMININ = 'F';
}
