<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\PointFocal;

final class PointFocalNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(PointFocal $pointFocal): array
    {
        return [
            'uuid' => $pointFocal->getUuid()->toRfc4122(),
            'structure' => [
                'uuid' => $pointFocal->getStructure()->getUuid()->toRfc4122(),
                'sigle' => $pointFocal->getStructure()->getSigle(),
            ],
            'nomComplet' => $pointFocal->getNomComplet(),
            'fonction' => $pointFocal->getFonction(),
            'telephone' => $pointFocal->getTelephone(),
            'email' => $pointFocal->getEmail(),
            'principal' => $pointFocal->estPrincipal(),
            'dateDesignation' => $pointFocal->getDateDesignation()?->format('Y-m-d'),
            'actif' => $pointFocal->estActif(),
            'utilisateur' => null !== $pointFocal->getUtilisateur() ? [
                'uuid' => $pointFocal->getUtilisateur()->getUuid()->toRfc4122(),
                'email' => $pointFocal->getUtilisateur()->getEmail(),
            ] : null,
        ];
    }
}
