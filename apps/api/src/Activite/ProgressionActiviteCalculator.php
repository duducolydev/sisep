<?php

declare(strict_types=1);

namespace App\Activite;

use App\Entity\Activite;
use App\Entity\EtapeActivite;
use App\Entity\SousActivite;
use Doctrine\Common\Collections\Collection;

/**
 * Progression d'une activité, déduite de l'avancement de ses étapes (§ 6 du
 * CR du 22/09/2026) — calculée à la demande, jamais stockée, comme toute
 * grandeur dérivée du projet.
 *
 * Depuis les retours des RSE (octobre 2026), chaque ligne du PTBA
 * (sous-activité) porte ses propres étapes. La règle devient :
 *  - progression d'une ligne = moyenne simple de ses étapes ;
 *  - progression d'une activité = moyenne de ses « blocs » structurés :
 *    chaque ligne qui a des étapes, plus les étapes propres de l'activité
 *    si elle en a, comptées comme un bloc de plus.
 * Chaque ligne pèse donc pareil, quel que soit son nombre d'étapes : une
 * opération découpée finement ne doit pas écraser les autres. ArbreReader
 * applique la même règle en SQL.
 *
 * Aucune étape → null, jamais 0 : une activité qu'on n'a pas encore
 * structurée n'est pas une activité à 0 % (§ 5.1, division par zéro).
 */
final class ProgressionActiviteCalculator
{
    public static function calculer(Activite $activite): ?float
    {
        $blocs = [];

        $propres = self::moyenne($activite->getEtapesPropres());
        if (null !== $propres) {
            $blocs[] = $propres;
        }
        foreach ($activite->getSousActivitesActives() as $ligne) {
            $progressionLigne = self::calculerLigne($ligne);
            if (null !== $progressionLigne) {
                $blocs[] = $progressionLigne;
            }
        }

        return [] === $blocs ? null : array_sum($blocs) / \count($blocs);
    }

    public static function calculerLigne(SousActivite $ligne): ?float
    {
        return self::moyenne($ligne->getEtapesActives());
    }

    /**
     * Part des étapes déjà achevées, lignes comprises — complète la moyenne,
     * qui ne dit pas combien de jalons sont réellement passés (3 étapes à
     * 60 % et 2 étapes terminées sur 5 donnent la même moyenne, pas le même
     * terrain).
     */
    public static function etapesAchevees(Activite $activite): int
    {
        return self::achevees($activite->getEtapesActives());
    }

    public static function etapesAcheveesLigne(SousActivite $ligne): int
    {
        return self::achevees($ligne->getEtapesActives());
    }

    /** @param Collection<int, EtapeActivite> $etapes */
    private static function moyenne(Collection $etapes): ?float
    {
        if (0 === $etapes->count()) {
            return null;
        }

        $total = 0;
        foreach ($etapes as $etape) {
            $total += $etape->getAvancement();
        }

        return $total / $etapes->count() / 100;
    }

    /** @param Collection<int, EtapeActivite> $etapes */
    private static function achevees(Collection $etapes): int
    {
        return $etapes->filter(static fn (EtapeActivite $e) => 100 === $e->getAvancement())->count();
    }
}
