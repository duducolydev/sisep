<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\JournalActivite;

final class JournalActiviteNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(JournalActivite $entree): array
    {
        return [
            'id' => (string) $entree->getId(),
            'utilisateur' => $entree->getUtilisateur()?->getNomComplet(),
            'action' => $entree->getAction(),
            'entiteType' => $entree->getEntiteType(),
            'entiteId' => $entree->getEntiteId(),
            'avant' => $entree->getAvant(),
            'apres' => $entree->getApres(),
            'adresseIp' => $entree->getAdresseIp(),
            'creeLe' => $entree->getCreeLe()->format(\DateTimeInterface::ATOM),
        ];
    }
}
