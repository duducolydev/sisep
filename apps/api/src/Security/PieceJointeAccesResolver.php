<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\PieceJointe;
use App\Entity\Utilisateur;
use App\Enum\VisibilitePieceJointe;

/**
 * Contrôle d'accès en téléchargement d'une pièce jointe — distinct de la
 * portée par structure (PorteeParStructureInterface) : ici la portée est
 * par groupe de rôle (équipe PHASAOC / sectoriels) ou par personne nommée,
 * pas par structure.
 */
final class PieceJointeAccesResolver
{
    /** @var list<string> */
    private const CODES_EQUIPE_PHASAOC = ['coordonnateur', 'responsable_suivi_evaluation', 'gestionnaire_budgetaire'];

    private const CODE_SECTORIEL = 'point_focal_structure';

    /** entiteType des pièces jointes du module Projets (InitiativeController). */
    private const ENTITES_PROJETS = ['initiative_activite', 'tache_initiative'];

    public function __construct(private readonly PermissionsResolver $permissionsResolver)
    {
    }

    public function estAccessible(PieceJointe $piece, Utilisateur $utilisateur): bool
    {
        if ($piece->getCreePar()->getUuid()->equals($utilisateur->getUuid())) {
            return true;
        }

        $permissions = $this->permissionsResolver->pour($utilisateur);
        // Pièces des projets : le module est réservé à l'UGP, quelle que soit
        // la visibilité choisie au dépôt (décision du 26/10/2026).
        if (\in_array($piece->getEntiteType(), self::ENTITES_PROJETS, true) && !$permissions->estGlobale('initiatives.consulter')) {
            return false;
        }

        if ($permissions->estGlobale('initiatives.gerer')) {
            return true;
        }

        return match ($piece->getVisibilite()) {
            VisibilitePieceJointe::TOUS => true,
            VisibilitePieceJointe::EQUIPE_PHASAOC => $this->detientUnRole($utilisateur, self::CODES_EQUIPE_PHASAOC),
            VisibilitePieceJointe::SECTORIELS => $this->detientUnRole($utilisateur, [self::CODE_SECTORIEL]),
            VisibilitePieceJointe::AGENTS_SPECIFIQUES => $piece->getAgentsVisibilite()->exists(
                static fn (int $i, Utilisateur $agent) => $agent->getUuid()->equals($utilisateur->getUuid()),
            ),
        };
    }

    /** @param list<string> $codes */
    private function detientUnRole(Utilisateur $utilisateur, array $codes): bool
    {
        foreach ($utilisateur->getAttributionsRoles() as $attribution) {
            if (\in_array($attribution->getRole()->getCode(), $codes, true)) {
                return true;
            }
        }

        return false;
    }
}
