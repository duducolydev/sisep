<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'un document joint — ce qu'il est (rapport, compte-rendu,
 * manuel…), à distinguer de son format (FamilleDocument, déduite du MIME).
 * Choisie au dépôt (observations des utilisateurs, octobre 2026) ; « Autre »
 * s'accompagne d'une précision libre.
 */
enum TypeDocument: string
{
    case RAPPORT = 'rapport';
    case COMPTE_RENDU = 'compte_rendu';
    case MANUEL = 'manuel';
    case PROCES_VERBAL = 'proces_verbal';
    case TERMES_REFERENCE = 'termes_reference';
    case NOTE = 'note';
    case PRESENTATION = 'presentation';
    case CONTRAT = 'contrat';
    case FACTURE = 'facture';
    case LISTE_PRESENCE = 'liste_presence';
    case PHOTO = 'photo';
    case CORRESPONDANCE = 'correspondance';
    case AUTRE = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::RAPPORT => 'Rapport',
            self::COMPTE_RENDU => 'Compte-rendu',
            self::MANUEL => 'Manuel / guide',
            self::PROCES_VERBAL => 'Procès-verbal',
            self::TERMES_REFERENCE => 'Termes de référence',
            self::NOTE => 'Note technique',
            self::PRESENTATION => 'Présentation',
            self::CONTRAT => 'Contrat / convention',
            self::FACTURE => 'Facture / pièce comptable',
            self::LISTE_PRESENCE => 'Liste de présence',
            self::PHOTO => 'Photo',
            self::CORRESPONDANCE => 'Correspondance',
            self::AUTRE => 'Autre',
        };
    }
}
