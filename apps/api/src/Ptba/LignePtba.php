<?php

declare(strict_types=1);

namespace App\Ptba;

/**
 * Une ligne du PTBA, telle qu'elle est lue dans le classeur.
 *
 * Le code dit le niveau : « 1. » est une composante, « 1.2. » une
 * sous-composante, « 1.2.1. » une activité, « 1.2.1.2 » une sous-activité —
 * la maille budgétaire. C'est la hiérarchie du § 2.2 du brief, telle quelle.
 *
 * Les montants sont des entiers FCFA. Le classeur les porte en flottants
 * (une division par trois traîne dans ses formules) : ils sont arrondis à
 * l'entier le plus proche à la lecture, une fois pour toutes, parce que rien
 * en aval n'a le droit de manipuler un montant en flottant.
 */
final readonly class LignePtba
{
    public function __construct(
        /** Code hiérarchique sans point final : « 1.2.1 ». */
        public string $code,
        public string $libelle,
        /** 1 = composante, 2 = sous-composante, 3 = activité, 4 = sous-activité. */
        public int $niveau,
        /** Budget notifié de l'exercice, en FCFA entiers. */
        public int $montant,
        public ?int $trimestre1,
        public ?int $trimestre2,
        public ?int $trimestre3,
        public ?int $trimestre4,
        /** Montant validé par le COPIL, quand le classeur le distingue du notifié. */
        public ?int $montantValideCopil,
        public ?string $commentaire,
        public int $ligne,
    ) {
    }

    /** Code du parent, ou null au niveau composante. */
    public function codeParent(): ?string
    {
        $position = strrpos($this->code, '.');

        return false === $position ? null : substr($this->code, 0, $position);
    }
}
