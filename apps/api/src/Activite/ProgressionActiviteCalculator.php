<?php

declare(strict_types=1);

namespace App\Activite;

use App\Entity\Activite;
use App\Entity\EtapeActivite;

/**
 * Progression d'une activité, déduite de l'avancement de ses étapes (§ 6 du
 * CR du 22/09/2026) — calculée à la demande, jamais stockée, comme toute
 * grandeur dérivée du projet.
 *
 * Les étapes pèsent toutes pareil : le CR demande de « visualiser la
 * progression globale de l'activité » sans parler de pondération, et une
 * moyenne simple reste explicable à un point focal. Si une pondération
 * devient nécessaire, elle se posera sur EtapeActivite sans changer les
 * appelants.
 *
 * Aucune étape → null, jamais 0 : une activité qu'on n'a pas encore
 * structurée n'est pas une activité à 0 % (§ 5.1, division par zéro).
 */
final class ProgressionActiviteCalculator
{
    public static function calculer(Activite $activite): ?float
    {
        $etapes = $activite->getEtapesActives();
        if (0 === $etapes->count()) {
            return null;
        }

        $total = 0;
        foreach ($etapes as $etape) {
            $total += $etape->getAvancement();
        }

        return $total / $etapes->count() / 100;
    }

    /**
     * Part des étapes déjà achevées — complète la moyenne, qui ne dit pas
     * combien de jalons sont réellement passés (3 étapes à 60 % et 2 étapes
     * terminées sur 5 donnent la même moyenne, pas le même terrain).
     */
    public static function etapesAchevees(Activite $activite): int
    {
        return $activite->getEtapesActives()
            ->filter(static fn (EtapeActivite $e) => 100 === $e->getAvancement())
            ->count();
    }
}
