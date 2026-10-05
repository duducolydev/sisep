<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RapportPeriodique;

final class RapportPeriodiqueNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(RapportPeriodique $rapport, \DateTimeImmutable $maintenant): array
    {
        $enRetard = $rapport->estEnRetard($maintenant);

        return [
            'uuid' => $rapport->getUuid()->toRfc4122(),
            'structure' => [
                'uuid' => $rapport->getStructure()->getUuid()->toRfc4122(),
                'sigle' => $rapport->getStructure()->getSigle(),
                'denomination' => $rapport->getStructure()->getDenomination(),
            ],
            'exercice' => $rapport->getExercice()->getAnnee(),
            'periode' => $rapport->getPeriode()->value,
            'statut' => $rapport->getStatut()->value,
            // Dérivé, jamais stocké (§ 5.5 du brief, § 4.1 CLAUDE.md) : distinct
            // du statut saisi, comme la vigilance des activités (§ 5.3).
            'enRetard' => $enRetard,
            'statutAffiche' => $enRetard ? 'retard' : $rapport->getStatut()->value,
            'dateEcheance' => $rapport->getDateEcheance()->format('Y-m-d'),
            'dateTransmission' => $rapport->getDateTransmission()?->format(\DateTimeInterface::ATOM),
            'pieceJointe' => null !== $rapport->getPieceJointe() ? [
                'uuid' => $rapport->getPieceJointe()->getUuid()->toRfc4122(),
                'nomFichier' => $rapport->getPieceJointe()->getNomFichier(),
            ] : null,
            'commentaire' => $rapport->getCommentaire(),
            'derniereRelanceLe' => $rapport->getDerniereRelanceLe()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
