<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Permission;

final class PermissionNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Permission $permission): array
    {
        return [
            'uuid' => $permission->getUuid()->toRfc4122(),
            'code' => $permission->getCode(),
            'groupe' => $permission->getGroupe(),
            'libelle' => $permission->getLibelle(),
        ];
    }
}
