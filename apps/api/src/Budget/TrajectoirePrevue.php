<?php

declare(strict_types=1);

namespace App\Budget;

/**
 * Part cumulée d'une dotation prévue à la fin d'un mois, selon la répartition
 * trimestrielle du PTBA : au PTBA 2026, 75 % du budget est prévu à fin juin, là
 * où une droite en annonçait 50 %. À l'intérieur d'un trimestre, ses trois mois
 * pèsent autant.
 *
 * Les trimestres donnent le rythme et non le montant : ils sont rapportés au
 * notifié, si bien qu'une dotation révisée garde son rythme et que la trajectoire
 * finit toujours exactement sur le notifié. Sans trimestres, ou sur un exercice
 * qui n'est pas une année civile, elle reste linéaire au prorata des jours.
 *
 * Calcul en bcmath : un montant FCFA multiplié par un autre dépasse les entiers
 * PHP, et aucun flottant n'a sa place sur un montant.
 */
final class TrajectoirePrevue
{
    /** @param array{0: ?int, 1: ?int, 2: ?int, 3: ?int} $trimestres */
    public static function cumul(int $montant, array $trimestres, \DateTimeImmutable $debut, \DateTimeImmutable $fin, \DateTimeImmutable $finMois): int
    {
        $total = array_sum($trimestres);
        if ($total > 0 && '01-01' === $debut->format('m-d') && '12-31' === $fin->format('m-d')) {
            $mois = (int) $finMois->format('n');
            $trimestre = (int) ceil($mois / 3);
            $precedents = array_sum(\array_slice($trimestres, 0, $trimestre - 1));
            $moisDuTrimestre = $mois - 3 * ($trimestre - 1);

            return self::part($montant, 3 * $precedents + $moisDuTrimestre * ($trimestres[$trimestre - 1] ?? 0), 3 * $total);
        }

        $joursTotaux = max(1, (int) $debut->diff($fin)->days);
        $joursEcoules = min($joursTotaux, max(0, (int) $debut->diff($finMois)->days + 1));

        return self::part($montant, $joursEcoules, $joursTotaux);
    }

    /** montant × numérateur / dénominateur, arrondi à l'entier le plus proche. */
    private static function part(int $montant, int $numerateur, int $denominateur): int
    {
        $double = bcmul('2', bcmul((string) $montant, (string) $numerateur));

        return (int) bcdiv(bcadd($double, (string) $denominateur), (string) (2 * $denominateur), 0);
    }
}
