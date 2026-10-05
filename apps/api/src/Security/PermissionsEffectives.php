<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Permissions effectives d'un utilisateur, avec leur portée : `true` pour
 * une portée globale, sinon la liste des id internes de structures
 * autorisées (§ 7.2 du brief).
 *
 * Ces id sont les BIGINT internes, jamais exposés tels quels côté API
 * (§ 3.3) — cette classe est un objet de travail serveur pour le Voter et
 * le filtre SQL, pas une DTO de réponse HTTP.
 */
final class PermissionsEffectives
{
    /** @param array<string, true|list<int>> $parCode */
    public function __construct(private readonly array $parCode)
    {
    }

    public function possede(string $code): bool
    {
        return isset($this->parCode[$code]);
    }

    public function estGlobale(string $code): bool
    {
        return ($this->parCode[$code] ?? null) === true;
    }

    public function estAutorisePour(string $code, ?int $structureId): bool
    {
        if (!isset($this->parCode[$code])) {
            return false;
        }

        $portee = $this->parCode[$code];
        if (true === $portee) {
            return true;
        }

        return null !== $structureId && \in_array($structureId, $portee, true);
    }

    /** @return list<int> id internes des structures autorisées pour ce code ; vide si non détenue ou globale */
    public function structuresAutorisees(string $code): array
    {
        $portee = $this->parCode[$code] ?? [];

        return true === $portee ? [] : $portee;
    }
}
