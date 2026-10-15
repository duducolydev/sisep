<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\IndicateurValeur;
use App\Repository\PieceJointeRepository;

final class IndicateurValeurNormalizer
{
    public function __construct(
        private readonly IndicateurDesagregationNormalizer $desagregationNormalizer,
        private readonly PieceJointeRepository $piecesJointes,
    ) {
    }

    /** @return array<string, mixed> */
    public function normaliser(IndicateurValeur $valeur): array
    {
        return [
            'uuid' => $valeur->getUuid()->toRfc4122(),
            'periode' => $valeur->getPeriode()->format('Y-m-d'),
            'valeur' => $valeur->getValeur(),
            'commentaire' => $valeur->getCommentaire(),
            'statut' => $valeur->getStatut()->value,
            'desagregations' => array_map(
                $this->desagregationNormalizer->normaliser(...),
                $valeur->getDesagregations()->toArray(),
            ),
            'piecesJointes' => $this->normaliserPiecesJointes((int) $valeur->getId()),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normaliserPiecesJointes(int $valeurId): array
    {
        $pieces = $this->piecesJointes->findBy(['entiteType' => 'indicateur_valeur', 'entiteId' => $valeurId], ['creeLe' => 'ASC']);

        return array_map(static fn ($p) => [
            'uuid' => $p->getUuid()->toRfc4122(),
            'nomFichier' => $p->getNomFichier(),
            'typeDocument' => $p->getTypeDocument()?->value,
            'typeDocumentLibelle' => $p->libelleTypeDocument(),
            'mime' => $p->getMime(),
            'tailleOctets' => $p->getTailleOctets(),
            'creeLe' => $p->getCreeLe()->format(\DateTimeInterface::ATOM),
        ], $pieces);
    }
}
