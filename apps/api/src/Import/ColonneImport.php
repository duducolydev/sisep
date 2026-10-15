<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Colonne attendue d'un classeur d'import : son en-tête dans le modèle, les
 * variantes reconnues à la lecture (un utilisateur écrit « Prenom », « Prénom »
 * ou « Prénom(s) »), et ce qu'en dit la feuille d'instructions.
 */
final class ColonneImport
{
    /**
     * @param list<string> $alias autres en-têtes acceptés à la lecture
     * @param string|null  $liste nom d'une liste de valeurs du modèle (validation Excel)
     */
    public function __construct(
        public readonly string $cle,
        public readonly string $entete,
        public readonly bool $obligatoire,
        public readonly string $description,
        public readonly string $exemple = '',
        public readonly array $alias = [],
        public readonly ?string $liste = null,
        public readonly bool $date = false,
        public readonly float $largeur = 18,
    ) {
    }
}
