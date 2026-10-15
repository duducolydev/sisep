<?php

declare(strict_types=1);

namespace App\Import;

/** Fichier refusé dans son ensemble (format, taille, en-têtes) — avant toute lecture ligne à ligne. */
final class ImportInvalideException extends \RuntimeException
{
}
