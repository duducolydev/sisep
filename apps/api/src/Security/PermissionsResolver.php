<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;

/**
 * Agrège les attributions rôle × structure (§ 4.5) en permissions
 * effectives : si une même permission est accordée à la fois globalement et
 * pour une structure donnée, la portée globale l'emporte (elle est un
 * sur-ensemble).
 */
final class PermissionsResolver
{
    public function pour(Utilisateur $utilisateur): PermissionsEffectives
    {
        /** @var array<string, true|list<int>> $parCode */
        $parCode = [];

        foreach ($utilisateur->getAttributionsRoles() as $attribution) {
            $structure = $attribution->getStructure();

            foreach ($attribution->getRole()->getPermissions() as $permission) {
                $code = $permission->getCode();

                if (($parCode[$code] ?? null) === true) {
                    continue;
                }

                if (null === $structure) {
                    $parCode[$code] = true;
                    continue;
                }

                $parCode[$code][] = $structure->getId();
            }
        }

        return new PermissionsEffectives($parCode);
    }
}
