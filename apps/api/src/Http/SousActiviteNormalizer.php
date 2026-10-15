<?php

declare(strict_types=1);

namespace App\Http;

use App\Activite\ProgressionActiviteCalculator;
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
            // Étapes de la ligne (retours des RSE) : progression déduite,
            // null tant que la ligne n'est pas structurée.
            'progression' => ProgressionActiviteCalculator::calculerLigne($sousActivite),
            'etapesTotal' => $sousActivite->getEtapesActives()->count(),
            'etapesAchevees' => ProgressionActiviteCalculator::etapesAcheveesLigne($sousActivite),
            'dateDebut' => $sousActivite->getDateDebut()?->format('Y-m-d'),
            'echeance' => $sousActivite->getEcheance()?->format('Y-m-d'),
            'structureResponsable' => null === $sousActivite->getStructureResponsable() ? null : [
                'uuid' => $sousActivite->getStructureResponsable()->getUuid()->toRfc4122(),
                'sigle' => $sousActivite->getStructureResponsable()->getSigle(),
            ],
        ];
    }
}
