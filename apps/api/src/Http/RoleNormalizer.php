<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Role;

final class RoleNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Role $role): array
    {
        return [
            'uuid' => $role->getUuid()->toRfc4122(),
            'code' => $role->getCode(),
            'libelle' => $role->getLibelle(),
            'description' => $role->getDescription(),
            'systeme' => $role->estSysteme(),
            'permissions' => array_map(static fn ($p) => $p->getCode(), $role->getPermissions()->toArray()),
            // Role::exigePorteeGlobale() : l'écran d'attribution grise la portée.
            'porteeGlobaleExigee' => $role->exigePorteeGlobale(),
        ];
    }
}
