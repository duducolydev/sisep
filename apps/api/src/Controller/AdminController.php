<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Http\ParametreNormalizer;
use App\Http\PermissionNormalizer;
use App\Http\ProblemResponseFactory;
use App\Http\RoleNormalizer;
use App\Repository\ParametreRepository;
use App\Repository\PermissionRepository;
use App\Repository\RoleRepository;
use App\Security\PermissionsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Onglets "Rôles et permissions" et "Paramètres" de l'administration
 * (§ 2.6, § 6.2). admin.roles et admin.parametres n'existent qu'en portée
 * globale dans le référentiel du § 7.2 (seul le coordonnateur les détient) :
 * exigerPermissionGlobale suffit ici, contrairement à Structure/PointFocal.
 */
#[Route('/api/v1/admin')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AdminController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly RoleRepository $roles,
        private readonly PermissionRepository $permissions,
        private readonly ParametreRepository $parametres,
        private readonly RoleNormalizer $roleNormalizer,
        private readonly PermissionNormalizer $permissionNormalizer,
        private readonly ParametreNormalizer $parametreNormalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    #[Route('/roles', name: 'app_admin_roles_liste', methods: ['GET'])]
    public function rolesListe(): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.roles');

        return new JsonResponse([
            'donnees' => array_map($this->roleNormalizer->normaliser(...), $this->roles->findBy([], ['libelle' => 'ASC'])),
        ]);
    }

    #[Route('/permissions', name: 'app_admin_permissions_liste', methods: ['GET'])]
    public function permissionsListe(): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.roles');

        return new JsonResponse([
            'donnees' => array_map($this->permissionNormalizer->normaliser(...), $this->permissions->findBy([], ['groupe' => 'ASC', 'code' => 'ASC'])),
        ]);
    }

    /**
     * Remplace intégralement l'ensemble des permissions du rôle (§ 6.2 :
     * "{ permissions: [code] }") — pas d'accorder/révoquer un par un, la
     * matrice envoie l'état complet de la ligne à chaque bascule.
     */
    #[Route('/roles/{uuid}/permissions', name: 'app_admin_role_permissions_modifier', methods: ['PUT'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function roleModifierPermissions(string $uuid, Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.roles');

        $uuidObjet = $this->uuidOuNull($uuid);
        $role = null !== $uuidObjet ? $this->roles->findOneBy(['uuid' => $uuidObjet]) : null;
        if (null === $role) {
            throw new NotFoundHttpException('Rôle introuvable.');
        }

        /** @var array<string, mixed> $corps */
        $corps = json_decode($request->getContent(), true) ?? [];
        $codes = $corps['permissions'] ?? null;
        if (!\is_array($codes)) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'permissions doit être un tableau de codes.');
        }

        $permissionsVoulues = [];
        foreach ($codes as $code) {
            if (!\is_string($code)) {
                continue;
            }
            $permission = $this->permissions->findOneBy(['code' => $code]);
            if (null === $permission) {
                return ProblemResponseFactory::creer(422, 'Données invalides', \sprintf('Permission inconnue : %s.', $code));
            }
            $permissionsVoulues[] = $permission;
        }

        foreach ($role->getPermissions()->toArray() as $actuelle) {
            if (!\in_array($actuelle, $permissionsVoulues, true)) {
                $role->revoquer($actuelle);
            }
        }
        foreach ($permissionsVoulues as $voulue) {
            $role->accorder($voulue);
        }

        $this->em->flush();

        return new JsonResponse($this->roleNormalizer->normaliser($role));
    }

    #[Route('/parametres', name: 'app_admin_parametres_liste', methods: ['GET'])]
    public function parametresListe(): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.parametres');

        return new JsonResponse([
            'donnees' => array_map($this->parametreNormalizer->normaliser(...), $this->parametres->listerToutes()),
        ]);
    }

    /**
     * { code: valeur, ... } — seuls les paramètres déjà seedés peuvent être
     * modifiés (§ 5.3 : les seuils de vigilance et l'échéance de reporting
     * sont un référentiel connu, pas un magasin clé-valeur ouvert).
     * Critère d'acceptation P6 : la valeur est relue à chaque calcul de
     * vigilance (VigilanceCalculator), donc effective dès ce flush, sans
     * redéploiement ni purge de cache.
     */
    #[Route('/parametres', name: 'app_admin_parametres_modifier', methods: ['PUT'])]
    public function parametresModifier(Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.parametres');

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        /** @var array<int|string, mixed> $corps */
        $corps = json_decode($request->getContent(), true) ?? [];

        foreach ($corps as $code => $valeur) {
            if (!\is_string($code) || !\is_string($valeur)) {
                return ProblemResponseFactory::creer(422, 'Données invalides', 'Chaque entrée doit être code => valeur (chaînes).');
            }
            $parametre = $this->parametres->findOneBy(['code' => $code]);
            if (null === $parametre) {
                return ProblemResponseFactory::creer(422, 'Paramètre inconnu', \sprintf('Aucun paramètre "%s".', $code));
            }
            $parametre->definirValeur($valeur, $utilisateur);
        }

        $this->em->flush();

        return new JsonResponse([
            'donnees' => array_map($this->parametreNormalizer->normaliser(...), $this->parametres->listerToutes()),
        ]);
    }
}
