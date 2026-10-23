<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Utilisateur;

final class UtilisateurNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Utilisateur $utilisateur): array
    {
        return [
            'uuid' => $utilisateur->getUuid()->toRfc4122(),
            'email' => $utilisateur->getEmail(),
            'nom' => $utilisateur->getNom(),
            'prenom' => $utilisateur->getPrenom(),
            'fonction' => $utilisateur->getFonction(),
            'structure' => null !== $utilisateur->getStructure() ? [
                'uuid' => $utilisateur->getStructure()->getUuid()->toRfc4122(),
                'sigle' => $utilisateur->getStructure()->getSigle(),
            ] : null,
            'actif' => $utilisateur->estActif(),
            'doitChangerMdp' => $utilisateur->doitChangerMdp(),
            'derniereConnexion' => $utilisateur->getDerniereConnexion()?->format(\DateTimeInterface::ATOM),
            // array_values : après un retrait, la collection garde des clés
            // trouées, qui feraient de la liste un objet JSON.
            'roles' => array_values(array_map(static fn ($attribution) => [
                'role' => $attribution->getRole()->getCode(),
                'structure' => $attribution->getStructure()?->getUuid()->toRfc4122(),
            ], $utilisateur->getAttributionsRoles()->toArray())),
        ];
    }
}
