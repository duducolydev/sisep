<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Depense;

final class DepenseNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Depense $depense): array
    {
        return [
            'uuid' => $depense->getUuid()->toRfc4122(),
            'montant' => $depense->getMontant()->versFcfa(),
            'dateDepense' => $depense->getDateDepense()->format('Y-m-d'),
            'referencePaiement' => $depense->getReferencePaiement(),
            'statut' => $depense->getStatut()->value,
        ];
    }
}
