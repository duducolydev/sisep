<?php

declare(strict_types=1);

namespace App\PointsFocaux;

/**
 * Un contact du classeur des points focaux : une personne, rattachée à une
 * structure, désignée pour une ligne du PTBA.
 *
 * Le classeur groupe parfois plusieurs personnes dans la même cellule, une
 * par ligne, avec leurs téléphones et courriels alignés dans le même ordre.
 * Le lecteur les rend ici déjà séparées : une LigneContact = une personne.
 */
final readonly class LigneContact
{
    public function __construct(
        /** Code PTBA de la ligne suivie, « 1.2.2.1 » — une sous-activité. */
        public string $code,
        public string $libelleActivite,
        public string $nomComplet,
        /** Fonction quand le classeur la précise entre parenthèses après le nom. */
        public ?string $fonction,
        public ?string $telephone,
        public ?string $email,
        /** Intitulé brut de la colonne « Structure / Direction ». */
        public string $structure,
        public int $ligne,
    ) {
    }
}
