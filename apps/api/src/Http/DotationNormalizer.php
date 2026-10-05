<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Activite;
use App\Entity\Dotation;

final class DotationNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Dotation $dotation): array
    {
        $cible = $dotation->getCible();
        $montantValideCopil = $dotation->getMontantValideCopil();

        return [
            'uuid' => $dotation->getUuid()->toRfc4122(),
            'cible' => [
                'type' => $cible instanceof Activite ? 'activite' : 'sous_activite',
                'uuid' => $cible->getUuid()->toRfc4122(),
                'libelle' => $cible->getLibelle(),
            ],
            'exercice' => $dotation->getExercice()->getAnnee(),
            'montant' => $dotation->getMontant()->versFcfa(),
            'q1' => $dotation->getQ1()?->versFcfa(),
            'q2' => $dotation->getQ2()?->versFcfa(),
            'q3' => $dotation->getQ3()?->versFcfa(),
            'q4' => $dotation->getQ4()?->versFcfa(),
            'montantValideCopil' => $montantValideCopil?->versFcfa(),
            // § 5.1 : grandeur dérivée, jamais stockée — colonne "Montant
            // ajouté (+/-)" du PTBA : positif si le COPIL a augmenté la
            // dotation initiale, négatif si réduite.
            'ecartCopil' => null !== $montantValideCopil ? $montantValideCopil->versFcfa() - $dotation->getMontant()->versFcfa() : null,
            'commentaireRevision' => $dotation->getCommentaireRevision(),
        ];
    }
}
