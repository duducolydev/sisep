<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\Exercice;

/**
 * Une écriture porte une date de son exercice. Sans cela, une dépense de 2026
 * datée de 2027 compte dans le dépensé de l'exercice mais disparaît du
 * décaissement mois par mois et des rapports de période, qui se fondent sur la
 * date : les écrans ne concordent plus.
 */
final class ControleDateExercice
{
    public static function motif(Exercice $exercice, \DateTimeImmutable $date, string $libelle): ?string
    {
        if ($exercice->contient($date)) {
            return null;
        }

        return \sprintf(
            '%s doit tomber dans l\'exercice %d (du %s au %s).',
            $libelle,
            $exercice->getAnnee(),
            $exercice->getDateDebut()->format('d/m/Y'),
            $exercice->getDateFin()->format('d/m/Y'),
        );
    }
}
