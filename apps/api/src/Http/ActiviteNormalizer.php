<?php

declare(strict_types=1);

namespace App\Http;

use App\Activite\ProgressionActiviteCalculator;
use App\Entity\Activite;

/** N'expose jamais l'auto-increment interne, seulement uuid (§ 3.3 du brief). */
final class ActiviteNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Activite $activite): array
    {
        return [
            'uuid' => $activite->getUuid()->toRfc4122(),
            'code' => $activite->getCode(),
            'libelle' => $activite->getLibelle(),
            'description' => $activite->getDescription(),
            'statut' => $activite->getStatut()->value,
            'dateDebut' => $activite->getDateDebut()?->format('Y-m-d'),
            'dateFinPrevue' => $activite->getDateFinPrevue()?->format('Y-m-d'),
            'dateFinReelle' => $activite->getDateFinReelle()?->format('Y-m-d'),
            'structureResponsable' => [
                'uuid' => $activite->getStructureResponsable()->getUuid()->toRfc4122(),
                'sigle' => $activite->getStructureResponsable()->getSigle(),
                'denomination' => $activite->getStructureResponsable()->getDenomination(),
            ],
            // Progression déduite des étapes (§ 6 du CR du 22/09/2026) : null tant
            // que l'activité n'est pas structurée, jamais 0 — une activité
            // sans étapes n'est pas une activité à l'arrêt.
            'progression' => ProgressionActiviteCalculator::calculer($activite),
            'etapesTotal' => $activite->getEtapesActives()->count(),
            'etapesAchevees' => ProgressionActiviteCalculator::etapesAchevees($activite),
            'sousComposante' => [
                'uuid' => $activite->getSousComposante()->getUuid()->toRfc4122(),
                'code' => $activite->getSousComposante()->getCode(),
                'libelle' => $activite->getSousComposante()->getLibelle(),
            ],
        ];
    }
}
