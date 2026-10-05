<?php

declare(strict_types=1);

namespace App\Indicateur;

use App\Enum\SensIndicateur;

/**
 * § 5.2 du brief :
 *   sens croissant   : (actuel − reference) / (cible − reference)
 *   sens décroissant : (reference − actuel) / (reference − cible)
 *
 * Ne borne jamais la valeur à 100 % : la valeur réelle est conservée ici et
 * dans l'API — un indicateur à 130 % de sa cible est une information de
 * pilotage, pas une anomalie à écraser. Le bornage à l'affichage est la
 * responsabilité du front (§ 5.2). cible = reference → null (division par
 * zéro), tout comme une absence de valeur actuelle.
 */
final class TauxAtteinteCalculator
{
    public static function calculer(SensIndicateur $sens, string $reference, string $cible, ?string $actuel): ?float
    {
        if (null === $actuel) {
            return null;
        }

        $referenceF = (float) $reference;
        $cibleF = (float) $cible;
        $actuelF = (float) $actuel;

        if ($cibleF === $referenceF) {
            return null;
        }

        return SensIndicateur::CROISSANT === $sens
            ? ($actuelF - $referenceF) / ($cibleF - $referenceF)
            : ($referenceF - $actuelF) / ($referenceF - $cibleF);
    }
}
