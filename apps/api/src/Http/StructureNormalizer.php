<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Structure;

final class StructureNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Structure $structure): array
    {
        return [
            'uuid' => $structure->getUuid()->toRfc4122(),
            'sigle' => $structure->getSigle(),
            'denomination' => $structure->getDenomination(),
            'type' => $structure->getType()->value,
            'telephone' => $structure->getTelephone(),
            'email' => $structure->getEmail(),
            'adresse' => $structure->getAdresse(),
            'actif' => $structure->estActif(),
        ];
    }
}
