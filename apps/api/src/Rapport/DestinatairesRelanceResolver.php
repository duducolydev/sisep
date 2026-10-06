<?php

declare(strict_types=1);

namespace App\Rapport;

use App\Entity\Structure;
use App\Repository\PointFocalRepository;

/**
 * Adresses à relancer pour une structure : ses points focaux d'abord (ce
 * sont eux qui transmettent le rapport), l'adresse générique de la structure
 * en dernier recours. Doublons écartés, une structure pouvant avoir
 * plusieurs points focaux partageant une boîte.
 *
 * Extrait de RapportController : la relance se déclenche depuis l'écran de
 * pilotage comme depuis la tâche planifiée, et deux copies de cette règle
 * finiraient par diverger.
 */
final class DestinatairesRelanceResolver
{
    public function __construct(private readonly PointFocalRepository $pointsFocaux)
    {
    }

    /** @return list<string> */
    public function pour(Structure $structure): array
    {
        $adresses = [];
        foreach ($this->pointsFocaux->findBy(['structure' => $structure]) as $pointFocal) {
            $email = $pointFocal->getEmail();
            if (null !== $email && '' !== $email) {
                $adresses[] = $email;
            }
        }

        if ([] === $adresses && null !== $structure->getEmail() && '' !== $structure->getEmail()) {
            $adresses[] = $structure->getEmail();
        }

        return array_values(array_unique($adresses));
    }
}
