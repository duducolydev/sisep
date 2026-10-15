<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un type de données importable par fichier Excel : ses colonnes (qui
 * donnent aussi le modèle téléchargeable), ses listes de valeurs, la
 * permission qu'il exige, et l'analyse ligne à ligne.
 */
#[AutoconfigureTag('app.definition_import')]
interface DefinitionImport
{
    /** Identifiant dans l'URL : /imports/{type}. */
    public function type(): string;

    public function titre(): string;

    /** Permission exigée pour télécharger le modèle et importer. */
    public function permission(): string;

    /** @return list<ColonneImport> */
    public function colonnes(): array;

    /**
     * Listes de valeurs proposées dans le modèle (validation Excel).
     *
     * @return array<string, list<string>>
     */
    public function listes(): array;

    /**
     * Analyse toutes les lignes sans rien écrire : doublons dans le fichier
     * et en base, erreurs de saisie, création ou mise à jour.
     *
     * @param array<int, array<string, string|float|int|null>> $lignes numéro de ligne Excel => valeurs
     *
     * @return list<LigneImport>
     */
    public function analyser(array $lignes, bool $miseAJour): array;
}
