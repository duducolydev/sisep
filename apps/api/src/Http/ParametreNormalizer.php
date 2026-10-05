<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Parametre;

final class ParametreNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Parametre $parametre): array
    {
        return [
            'code' => $parametre->getCode(),
            'valeur' => $parametre->getValeur(),
            'description' => $parametre->getDescription(),
            'modifieLe' => $parametre->getModifieLe()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
