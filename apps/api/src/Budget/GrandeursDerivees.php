<?php

declare(strict_types=1);

namespace App\Budget;

/**
 * § 5.1 du brief — jamais stockées, toujours calculées à la demande.
 * Division par zéro → null, jamais 0 : une activité sans dotation n'a pas
 * un taux de 0 %, elle n'a pas de taux.
 */
final class GrandeursDerivees
{
    public static function disponible(?int $notifie, ?int $engage): ?int
    {
        if (null === $notifie || null === $engage) {
            return null;
        }

        return $notifie - $engage;
    }

    public static function taux(?int $numerateur, ?int $denominateur): ?float
    {
        if (null === $numerateur || null === $denominateur || 0 === $denominateur) {
            return null;
        }

        return $numerateur / $denominateur;
    }
}
