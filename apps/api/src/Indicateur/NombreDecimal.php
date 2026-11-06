<?php

declare(strict_types=1);

namespace App\Indicateur;

/**
 * Valeurs d'indicateurs (référence, cible, intermédiaire, valeurs
 * périodiques, désagrégations) : colonnes DECIMAL(18,4). Les utilisateurs
 * saisissent à la française (« 0,5 », « 1 000 ») : envoyée telle quelle,
 * une telle chaîne faisait échouer l'INSERT en mode strict MySQL (erreur
 * 1366) et l'API répondait « Erreur interne » au lieu d'un 422.
 *
 * Normalise la virgule décimale et les séparateurs de milliers (espace,
 * espace insécable, espace fine insécable), puis vérifie que le résultat
 * tient dans DECIMAL(18,4) : 14 chiffres avant la virgule, 4 après. Tout
 * le reste (unité collée « 100 % », texte, notation scientifique) est
 * refusé — null — plutôt que deviné.
 */
final class NombreDecimal
{
    private const MOTIF = '/^-?\d{1,14}(\.\d{1,4})?$/';

    public static function normaliser(string $saisie): ?string
    {
        $nombre = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($saisie));
        $nombre = str_replace(',', '.', $nombre);

        return 1 === preg_match(self::MOTIF, $nombre) ? $nombre : null;
    }

    public static function message(string $libelleChamp): string
    {
        return \sprintf(
            '%s doit être un nombre (ex. 12, 0,5 ou 1 000), sans unité ni symbole, avec au plus 4 décimales.',
            $libelleChamp,
        );
    }
}
