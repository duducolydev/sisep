<?php

declare(strict_types=1);

namespace App\Classeur;

/**
 * Forme comparable d'un libellé lu dans un classeur : majuscules, sans
 * accent, espaces normalisés.
 *
 * Fonction pure partagée par les trois lecteurs plutôt que recopiée dans
 * chacun : la première version, qui retirait les accents avant de passer en
 * majuscules, laissait passer « Intitulé du projet » — les accents
 * minuscules n'étaient pas dans la table de correspondance et devenaient des
 * majuscules accentuées juste après. L'en-tête du PTBA n'était alors jamais
 * reconnu. L'ordre compte, et il n'a pas à être redécouvert trois fois.
 */
final class Normalisation
{
    public static function cle(string $valeur): string
    {
        $majuscules = mb_strtoupper(trim($valeur), 'UTF-8');

        $sansAccent = strtr($majuscules, [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N', 'Œ' => 'OE', 'Æ' => 'AE',
            // Espace insécable : invisible à la lecture, bloquant à la
            // comparaison. Les classeurs en sont pleins.
            "\u{a0}" => ' ',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $sansAccent) ?? '');
    }
}
