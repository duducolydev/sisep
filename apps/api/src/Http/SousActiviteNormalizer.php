<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SousActivite;

final class SousActiviteNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(SousActivite $sousActivite): array
    {
        return [
            'uuid' => $sousActivite->getUuid()->toRfc4122(),
            'libelle' => $sousActivite->getLibelle(),
            'statut' => $sousActivite->getStatut()->value,
            'avancement' => $sousActivite->getAvancement(),
            'dateDebut' => $sousActivite->getDateDebut()?->format('Y-m-d'),
            'echeance' => $sousActivite->getEcheance()?->format('Y-m-d'),
            'structureResponsable' => null === $sousActivite->getStructureResponsable() ? null : [
                'uuid' => $sousActivite->getStructureResponsable()->getUuid()->toRfc4122(),
                'sigle' => $sousActivite->getStructureResponsable()->getSigle(),
            ],
        ];
    }
}
