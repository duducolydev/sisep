<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Composante;

final class ComposanteNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Composante $composante): array
    {
        return [
            'uuid' => $composante->getUuid()->toRfc4122(),
            'code' => $composante->getCode(),
            'libelle' => $composante->getLibelle(),
            'description' => $composante->getDescription(),
            'ordre' => $composante->getOrdre(),
            'structurePilote' => null === $composante->getStructurePilote() ? null : [
                'uuid' => $composante->getStructurePilote()->getUuid()->toRfc4122(),
                'sigle' => $composante->getStructurePilote()->getSigle(),
            ],
        ];
    }
}
