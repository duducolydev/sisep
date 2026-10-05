<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Levée quand une écriture budgétaire viole une contrainte du § 5.1 du
 * brief (plafond dépassé, exercice clôturé, séparation saisie/validation).
 * Convertie en 422 avec message explicite par ApiExceptionListener.
 */
final class ContrainteBudgetaireException extends \DomainException
{
}
