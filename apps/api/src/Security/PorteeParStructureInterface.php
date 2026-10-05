<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Structure;

/**
 * Implémentée par les entités dont l'accès est restreint par structure
 * (§ 7.2 du brief) : le PermissionVoter s'appuie dessus pour vérifier la
 * portée d'une attribution de rôle contre l'entité visée.
 */
interface PorteeParStructureInterface
{
    public function getStructurePourPortee(): ?Structure;
}
