<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SousComposante;

final class SousComposanteNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(SousComposante $sousComposante): array
    {
        return [
            'uuid' => $sousComposante->getUuid()->toRfc4122(),
            'code' => $sousComposante->getCode(),
            'libelle' => $sousComposante->getLibelle(),
            'description' => $sousComposante->getDescription(),
            'ordre' => $sousComposante->getOrdre(),
            'composante' => [
                'uuid' => $sousComposante->getComposante()->getUuid()->toRfc4122(),
                'code' => $sousComposante->getComposante()->getCode(),
            ],
            'structurePilote' => null === $sousComposante->getStructurePilote() ? null : [
                'uuid' => $sousComposante->getStructurePilote()->getUuid()->toRfc4122(),
                'sigle' => $sousComposante->getStructurePilote()->getSigle(),
            ],
        ];
    }
}
