<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EtapeActivite;
use App\Entity\Livrable;
use App\Repository\PieceJointeRepository;

final class EtapeActiviteNormalizer
{
    public function __construct(private readonly PieceJointeRepository $piecesJointes)
    {
    }

    /** @return array<string, mixed> */
    public function normaliser(EtapeActivite $etape): array
    {
        return [
            'uuid' => $etape->getUuid()->toRfc4122(),
            'libelle' => $etape->getLibelle(),
            'ordre' => $etape->getOrdre(),
            'avancement' => $etape->getAvancement(),
            'valeurActuelle' => $etape->getValeurActuelle(),
            'unite' => $etape->getUnite(),
            'echeance' => $etape->getEcheance()?->format('Y-m-d'),
            'dateMiseAJour' => $etape->getDateMiseAJour()?->format('Y-m-d'),
            'observation' => $etape->getObservation(),
            'livrables' => array_map(static fn (Livrable $l) => [
                'uuid' => $l->getUuid()->toRfc4122(),
                'libelle' => $l->getLibelle(),
                'unite' => $l->getUnite(),
                'quantiteAttendue' => $l->getQuantiteAttendue(),
                'quantiteRealisee' => $l->getQuantiteRealisee(),
                'tauxRealisation' => $l->tauxRealisation(),
            ], $etape->getLivrables()->filter(static fn (Livrable $l) => !$l->estSupprime())->toArray()),
            'piecesJointes' => $this->normaliserPiecesJointes((int) $etape->getId()),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normaliserPiecesJointes(int $etapeId): array
    {
        $pieces = $this->piecesJointes->findBy(['entiteType' => 'etape_activite', 'entiteId' => $etapeId], ['creeLe' => 'ASC']);

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
