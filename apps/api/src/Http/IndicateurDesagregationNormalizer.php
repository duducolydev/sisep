<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\IndicateurDesagregation;

final class IndicateurDesagregationNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(IndicateurDesagregation $desagregation): array
    {
        return [
            'dimension' => $desagregation->getDimension(),
            'modalite' => $desagregation->getModalite(),
            'valeur' => $desagregation->getValeur(),
        ];
    }
}
