<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EtapeGabarit;
use App\Entity\GabaritEtapes;

final class GabaritEtapesNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(GabaritEtapes $gabarit): array
    {
        return [
            'uuid' => $gabarit->getUuid()->toRfc4122(),
            'libelle' => $gabarit->getLibelle(),
            'description' => $gabarit->getDescription(),
            'actif' => $gabarit->estActif(),
            'etapes' => array_map(static fn (EtapeGabarit $e) => [
                'uuid' => $e->getUuid()->toRfc4122(),
                'libelle' => $e->getLibelle(),
                'ordre' => $e->getOrdre(),
            ], $gabarit->getEtapes()->toArray()),
        ];
    }
}
