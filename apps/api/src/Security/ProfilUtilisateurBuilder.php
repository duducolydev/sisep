<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use App\Repository\InvitationIndicateurRepository;

/**
 * Forme la réponse de GET /auth/moi (§ 6.2). La liste "permissions" est une
 * liste à plat de codes, sans leur portée : elle sert au front à masquer des
 * actions dans l'IHM (§ 8), l'application stricte de la portée reste du
 * ressort du Voter côté serveur, jamais du front.
 *
 * "permissionsPortee" complète ce hint pour l'IHM avec la portée de chaque
 * code : `null` = attribution globale (autorisé partout), sinon la liste des
 * UUID de structures pour lesquelles le code est accordé. Reste un hint de
 * confort d'affichage (masquer un bouton dont on sait déjà qu'il sera
 * refusé) — jamais une source d'autorisation, toujours revérifiée par le
 * Voter côté serveur à l'écriture.
 */
final class ProfilUtilisateurBuilder
{
    public function __construct(private readonly InvitationIndicateurRepository $invitations)
    {
    }

    /** @return array<string, mixed> */
    public function construire(Utilisateur $utilisateur): array
    {
        $roles = [];
        $permissions = [];
        /** @var array<string, true|array<string, true>> $porteeParCode */
        $porteeParCode = [];

        foreach ($utilisateur->getAttributionsRoles() as $attribution) {
            $role = $attribution->getRole();
            $structureAttribution = $attribution->getStructure();
            $roles[] = [
                'code' => $role->getCode(),
                'libelle' => $role->getLibelle(),
                'structure' => $structureAttribution?->getSigle(),
            ];

            foreach ($role->getPermissions() as $permission) {
                $code = $permission->getCode();
                $permissions[$code] = true;

                if (($porteeParCode[$code] ?? null) === true) {
                    continue;
                }

                if (null === $structureAttribution) {
                    $porteeParCode[$code] = true;
                    continue;
                }

                $porteeParCode[$code][$structureAttribution->getUuid()->toRfc4122()] = true;
            }
        }

        $permissionsPortee = array_map(
            static fn (array|true $portee): ?array => true === $portee ? null : array_keys($portee),
            $porteeParCode,
        );

        // Hint cosmétique (§ 8), même principe que "permissions" ci-dessus :
        // un sectoriel invité sur un indicateur précis (InvitationIndicateur)
        // n'a jamais indicateurs.consulter en liste de permissions, mais a un
        // accès serveur réel à ce module — sans ce champ, le lien de
        // navigation resterait masqué malgré l'accès effectif.
        $aAccesIndicateurs = isset($permissions['indicateurs.consulter'])
            || [] !== $this->invitations->indicateursInvites($utilisateur);

        return [
            'uuid' => $utilisateur->getUuid()->toRfc4122(),
            'email' => $utilisateur->getEmail(),
            'nom' => $utilisateur->getNom(),
            'prenom' => $utilisateur->getPrenom(),
            'fonction' => $utilisateur->getFonction(),
            'structure' => $utilisateur->getStructure()?->getSigle(),
            'structureUuid' => $utilisateur->getStructure()?->getUuid()->toRfc4122(),
            'doitChangerMdp' => $utilisateur->doitChangerMdp(),
            'roles' => $roles,
            'permissions' => array_keys($permissions),
            'permissionsPortee' => $permissionsPortee,
            'aAccesIndicateurs' => $aAccesIndicateurs,
        ];
    }
}
