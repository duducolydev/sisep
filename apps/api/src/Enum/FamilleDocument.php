<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Famille d'un document, déduite de son type MIME.
 *
 * L'UGP cherche « les PDF » ou « les tableurs », pas
 * « application/vnd.openxmlformats-officedocument.spreadsheetml.sheet ». La
 * famille n'est pas stockée : elle se déduit du MIME à la demande, comme
 * toute grandeur dérivée (§ 5.1). Un même fichier reclassé par un navigateur
 * qui annonce un autre MIME change donc de famille sans migration.
 */
enum FamilleDocument: string
{
    case PDF = 'pdf';
    case TEXTE = 'texte';
    case TABLEUR = 'tableur';
    case PRESENTATION = 'presentation';
    case IMAGE = 'image';
    case AUTRE = 'autre';

    /**
     * Fragments de type MIME reconnaissant la famille. Comparés en
     * « contient » et non en égalité : les suites bureautiques déclinent le
     * même format sous une dizaine de MIME différents.
     *
     * @return list<string>
     */
    public function fragmentsMime(): array
    {
        return match ($this) {
            self::PDF => ['pdf'],
            self::TEXTE => ['word', 'opendocument.text', 'rtf', 'text/plain'],
            self::TABLEUR => ['excel', 'spreadsheet', 'csv'],
            self::PRESENTATION => ['powerpoint', 'presentation'],
            self::IMAGE => ['image/'],
            self::AUTRE => [],
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::PDF => 'PDF',
            self::TEXTE => 'Document texte',
            self::TABLEUR => 'Tableur',
            self::PRESENTATION => 'Présentation',
            self::IMAGE => 'Image',
            self::AUTRE => 'Autre',
        };
    }

    public static function depuisMime(string $mime): self
    {
        $mime = mb_strtolower($mime);

        // L'ordre compte : « opendocument.presentation » contient
        // « presentation », et ne doit pas être pris pour un texte.
        foreach ([self::PDF, self::TABLEUR, self::PRESENTATION, self::TEXTE, self::IMAGE] as $famille) {
            foreach ($famille->fragmentsMime() as $fragment) {
                if (str_contains($mime, $fragment)) {
                    return $famille;
                }
            }
        }

        return self::AUTRE;
    }
}
