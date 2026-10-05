<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Activite;
use App\Entity\Engagement;

final class EngagementNormalizer
{
    public function __construct(private readonly DepenseNormalizer $depenseNormalizer)
    {
    }

    /** @return array<string, mixed> */
    public function normaliser(Engagement $engagement): array
    {
        $cible = $engagement->getCible();

        return [
            'uuid' => $engagement->getUuid()->toRfc4122(),
            'cible' => [
                'type' => $cible instanceof Activite ? 'activite' : 'sous_activite',
                'uuid' => $cible->getUuid()->toRfc4122(),
                'libelle' => $cible->getLibelle(),
            ],
            'exercice' => $engagement->getExercice()->getAnnee(),
            'referenceMarche' => $engagement->getReferenceMarche(),
            'nature' => $engagement->getNature()->value,
            'montant' => $engagement->getMontant()->versFcfa(),
            'dateEngagement' => $engagement->getDateEngagement()->format('Y-m-d'),
            'statut' => $engagement->getStatut()->value,
            'depenses' => array_map(
                $this->depenseNormalizer->normaliser(...),
                $engagement->getDepenses()->toArray(),
            ),
        ];
    }
}
