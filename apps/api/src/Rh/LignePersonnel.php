<?php

declare(strict_types=1);

namespace App\Rh;

use App\Enum\Sexe;

/**
 * Une ligne de l'état du personnel technique de l'UGP, telle qu'elle est
 * lue dans le classeur — avant tout rapprochement avec la base.
 *
 * `sexe` est nullable alors que l'entité Personnel l'exige : le classeur
 * comporte des cellules vides, et c'est précisément ce que l'import doit
 * savoir signaler plutôt que de deviner.
 */
final readonly class LignePersonnel
{
    public function __construct(
        public string $prenom,
        public string $nom,
        public string $fonction,
        public ?Sexe $sexe,
        public ?\DateTimeImmutable $dateDebut,
        public ?\DateTimeImmutable $dateFin,
        /** Intitulé de l'opération, repris du titre de section du classeur. */
        public ?string $operation,
        /** Numéro de ligne dans la feuille — sert aux messages d'erreur de l'import. */
        public int $ligne,
    ) {
    }

    public function nomComplet(): string
    {
        return $this->prenom.' '.$this->nom;
    }
}
