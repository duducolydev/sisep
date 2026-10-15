<?php

declare(strict_types=1);

namespace App\Export;

/**
 * Libellés français des codes internes, pour que les extractions se lisent
 * sans dictionnaire : « En cours » plutôt que « en_cours ». Un code inconnu
 * ressort tel quel plutôt que de disparaître.
 */
final class LibellesExport
{
    private const STATUTS = [
        'planifiee' => 'Planifiée', 'en_cours' => 'En cours', 'terminee' => 'Terminée',
        'suspendue' => 'Suspendue', 'annulee' => 'Annulée',
        'brouillon' => 'Brouillon', 'valide' => 'Validé', 'annule' => 'Annulé',
        'a_risque' => 'À risque', 'en_retard' => 'En retard',
    ];

    private const NATURES = [
        'travaux' => 'Travaux', 'fournitures' => 'Fournitures de biens', 'acquisition_biens' => 'Acquisition de biens',
        'service_consultation' => 'Service de consultation', 'service_courant' => 'Service courant',
        'salaire' => 'Salaire', 'frais_mission' => 'Frais de mission', 'atelier' => 'Atelier',
        'remboursement_transport' => 'Remboursement de transport', 'autre_fonctionnement' => 'Autres natures de fonctionnement',
    ];

    private const TYPES_STRUCTURE = [
        'chef_de_file' => 'Chef de file', 'direction_ansd' => 'Direction ANSD', 'cellule_ansd' => 'Cellule ANSD',
        'partenaire_national' => 'Partenaire national', 'partenaire_technique' => 'Partenaire technique',
        'partenaire_formation' => 'Partenaire — formation', 'bailleur' => 'Bailleur',
    ];

    private const ACTIONS = ['creation' => 'Création', 'modification' => 'Modification', 'suppression' => 'Suppression'];

    public static function statut(?string $code): ?string
    {
        return null === $code ? null : (self::STATUTS[$code] ?? $code);
    }

    public static function nature(string $code): string
    {
        return self::NATURES[$code] ?? $code;
    }

    public static function typeStructure(string $code): string
    {
        return self::TYPES_STRUCTURE[$code] ?? $code;
    }

    public static function action(string $code): string
    {
        return self::ACTIONS[$code] ?? $code;
    }

    public static function ouiNon(bool $valeur): string
    {
        return $valeur ? 'Oui' : 'Non';
    }
}
