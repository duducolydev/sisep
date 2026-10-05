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

    public function __construct(private readonly PermissionsResolver $permissionsResolver)
    {
    }

    public function estAccessible(PieceJointe $piece, Utilisateur $utilisateur): bool
    {
        if ($piece->getCreePar()->getUuid()->equals($utilisateur->getUuid())) {
            return true;
        }

        if ($this->permissionsResolver->pour($utilisateur)->estGlobale('initiatives.gerer')) {
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
