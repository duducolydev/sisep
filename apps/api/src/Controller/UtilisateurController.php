<?php

declare(strict_types=1);

namespace App\Controller;

use App\Courriel\ServiceCourriel;
use App\Dto\CreerUtilisateurRequest;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Http\ProblemResponseFactory;
use App\Http\UtilisateurNormalizer;
use App\Repository\RoleRepository;
use App\Repository\StructureRepository;
use App\Repository\UtilisateurRepository;
use App\Security\PermissionsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\String\ByteString;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Comptes de connexion (§ 4.5, § 7). admin.utilisateurs n'existe qu'en
 * portée globale (seul le coordonnateur la détient) : exigerPermissionGlobale
 * suffit ici, comme pour admin.roles/admin.parametres (AdminController).
 *
 * Le mot de passe initial est généré côté serveur, envoyé par courriel au
 * titulaire du compte et renvoyé une seule fois dans la réponse de création
 * (recours si le message n'arrive pas) ; doitChangerMdp force son
 * remplacement à la première connexion.
 */
#[Route('/api/v1/admin/utilisateurs')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class UtilisateurController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly RoleRepository $roles,
        private readonly StructureRepository $structures,
        private readonly UtilisateurNormalizer $normalizer,
        private readonly ServiceCourriel $courriels,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_utilisateur_liste', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.utilisateurs');

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $this->utilisateurs->listerNonSupprimes()),
        ]);
    }

    #[Route('', name: 'app_utilisateur_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.utilisateurs');

        /** @var CreerUtilisateurRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerUtilisateurRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        if (null !== $this->utilisateurs->findOneBy(['email' => $donnees->email])) {
            return ProblemResponseFactory::creer(422, 'Données invalides', null, [
                'violations' => [['champ' => 'email', 'message' => 'Cet email est déjà utilisé.']],
            ]);
        }

        $structure = null;
        if (null !== $donnees->structure && '' !== $donnees->structure) {
            $structureUuid = $this->uuidOuNull($donnees->structure);
            $structure = null !== $structureUuid ? $this->structures->findOneBy(['uuid' => $structureUuid]) : null;
            if (null === $structure) {
                return ProblemResponseFactory::creer(422, 'Structure introuvable');
            }
        }

        $roles = [];
        foreach ($donnees->roles as $entree) {
            if (!\is_array($entree) || !isset($entree['role']) || !\is_string($entree['role'])) {
                return ProblemResponseFactory::creer(422, 'Données invalides', 'Chaque entrée de roles doit fournir un code de rôle.');
            }

            $role = $this->roles->findOneBy(['code' => $entree['role']]);
            if (null === $role) {
                return ProblemResponseFactory::creer(422, 'Données invalides', \sprintf('Rôle inconnu : %s.', $entree['role']));
            }

            $roleStructure = null;
            if (\is_string($entree['structure'] ?? null) && '' !== $entree['structure']) {
                $roleStructureUuid = $this->uuidOuNull($entree['structure']);
                $roleStructure = null !== $roleStructureUuid ? $this->structures->findOneBy(['uuid' => $roleStructureUuid]) : null;
                if (null === $roleStructure) {
                    return ProblemResponseFactory::creer(422, 'Structure introuvable pour l\'attribution de rôle');
                }
            }

            $roles[] = [$role, $roleStructure];
        }

        $motDePasseTemporaire = ByteString::fromRandom(20)->toString();

        $utilisateur = new Utilisateur($donnees->email, '', $donnees->nom, $donnees->prenom);
        $utilisateur->setMotDePasseHash($this->passwordHasher->hashPassword($utilisateur, $motDePasseTemporaire));
        $utilisateur->setFonction($donnees->fonction);
        $utilisateur->setStructure($structure);

        $this->em->persist($utilisateur);
        foreach ($roles as [$role, $roleStructure]) {
            $this->em->persist(new UtilisateurRole($utilisateur, $role, $roleStructure));
        }

        $this->em->flush();

        // Le mot de passe temporaire reste dans la réponse même quand le
        // courriel est parti : c'est le seul recours si le message n'arrive
        // pas (boîte pleine, filtre anti-spam), et il n'est plus jamais
        // affichable ensuite.
        $courrielEnvoye = $this->courriels->compteCree($utilisateur, $motDePasseTemporaire);

        return new JsonResponse(
            [
                ...$this->normalizer->normaliser($utilisateur),
                'motDePasseTemporaire' => $motDePasseTemporaire,
                'courrielEnvoye' => $courrielEnvoye,
            ],
            201,
        );
    }

    #[Route('/{uuid}', name: 'app_utilisateur_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.utilisateurs');

        $utilisateur = $this->trouver($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('nom', $donnees) && \is_string($donnees['nom']) && '' !== $donnees['nom']) {
            $utilisateur->setNom($donnees['nom']);
        }
        if (\array_key_exists('prenom', $donnees) && \is_string($donnees['prenom']) && '' !== $donnees['prenom']) {
            $utilisateur->setPrenom($donnees['prenom']);
        }
        if (\array_key_exists('fonction', $donnees)) {
            $utilisateur->setFonction(\is_string($donnees['fonction']) ? $donnees['fonction'] : null);
        }
        if (\array_key_exists('structureUuid', $donnees)) {
            if (null === $donnees['structureUuid']) {
                $utilisateur->setStructure(null);
            } elseif (\is_string($donnees['structureUuid'])) {
                $structureUuid = $this->uuidOuNull($donnees['structureUuid']);
                $structure = null !== $structureUuid ? $this->structures->findOneBy(['uuid' => $structureUuid]) : null;
                if (null === $structure) {
                    return ProblemResponseFactory::creer(422, 'Structure introuvable');
                }
                $utilisateur->setStructure($structure);
            }
        }
        if (\array_key_exists('actif', $donnees) && \is_bool($donnees['actif'])) {
            $utilisateur->setActif($donnees['actif']);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($utilisateur));
    }

    /**
     * Nouveau mot de passe temporaire pour un compte dont le titulaire a
     * oublié le sien — l'écran de connexion renvoie vers l'administrateur,
     * il n'existe pas de réinitialisation en libre-service.
     *
     * Même circuit qu'à la création : envoyé par courriel, et renvoyé une
     * seule fois dans la réponse pour le cas où le message n'arrive pas.
     * L'ancien mot de passe cesse aussitôt de fonctionner, les sessions
     * ouvertes avec lui aussi, et le nouveau devra être changé à la
     * connexion suivante : quelqu'un d'autre que son titulaire l'a vu.
     */
    #[Route('/{uuid}/mot-de-passe-temporaire', name: 'app_utilisateur_mot_de_passe_temporaire', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function motDePasseTemporaire(string $uuid): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.utilisateurs');

        $utilisateur = $this->trouver($uuid);
        $courant = $this->security->getUser();
        if ($courant instanceof Utilisateur && $courant->getUuid()->equals($utilisateur->getUuid())) {
            return ProblemResponseFactory::creer(422, 'Votre propre compte', 'Changez votre mot de passe depuis « Mon profil ».');
        }

        $motDePasseTemporaire = ByteString::fromRandom(20)->toString();
        $utilisateur->setMotDePasseHash($this->passwordHasher->hashPassword($utilisateur, $motDePasseTemporaire));
        $utilisateur->exigerChangementMdp();
        $this->em->flush();

        $courrielEnvoye = $this->courriels->motDePasseReinitialise($utilisateur, $motDePasseTemporaire);

        return new JsonResponse([
            'motDePasseTemporaire' => $motDePasseTemporaire,
            'courrielEnvoye' => $courrielEnvoye,
        ]);
    }

    /**
     * Remplace l'ensemble des rôles d'un compte (rôle + portée éventuelle).
     *
     * Deux garde-fous :
     *  - séparation saisie/validation (§ 7.2) : un cumul de rôles qui donne
     *    à la même personne budget.*.saisir et budget.*.valider est refusé,
     *    sauf si l'un des rôles est une des deux dérogations assumées
     *    (administrateur, responsable_suivi_evaluation) ;
     *  - un administrateur ne retire pas son propre rôle d'administration :
     *    la plateforme pourrait se retrouver sans personne pour la gérer.
     *
     * Chaque attribution ajoutée ou retirée est journalisée.
     */
    #[Route('/{uuid}/roles', name: 'app_utilisateur_roles', methods: ['PUT'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function definirRoles(string $uuid, Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.utilisateurs');
        $utilisateur = $this->trouver($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];
        if (!\is_array($donnees['roles'] ?? null)) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'roles doit être une liste.');
        }

        // Rôles demandés, sans doublon (même rôle, même portée).
        $voulus = [];
        foreach ($donnees['roles'] as $entree) {
            if (!\is_array($entree) || !\is_string($entree['role'] ?? null)) {
                return ProblemResponseFactory::creer(422, 'Données invalides', 'Chaque entrée doit fournir un code de rôle.');
            }
            $role = $this->roles->findOneBy(['code' => $entree['role']]);
            if (null === $role) {
                return ProblemResponseFactory::creer(422, 'Données invalides', \sprintf('Rôle inconnu : %s.', $entree['role']));
            }
            $structure = null;
            if (\is_string($entree['structure'] ?? null) && '' !== $entree['structure']) {
                $structure = $this->structures->findOneBy(['uuid' => $this->uuidOuNull($entree['structure'])]);
                if (null === $structure) {
                    return ProblemResponseFactory::creer(422, 'Structure introuvable pour l\'attribution de rôle');
                }
            }
            $voulus[$role->getCode().'|'.($structure?->getId() ?? '')] = [$role, $structure];
        }

        $codes = array_map(static fn (array $v) => $v[0]->getCode(), $voulus);
        $derogation = [] !== array_intersect($codes, ['administrateur', 'responsable_suivi_evaluation']);
        $permissions = [];
        foreach ($voulus as [$role]) {
            foreach ($role->getPermissions() as $permission) {
                $permissions[$permission->getCode()] = true;
            }
        }
        $saisit = isset($permissions['budget.engagement.saisir']) || isset($permissions['budget.depense.saisir']);
        $valide = isset($permissions['budget.engagement.valider']) || isset($permissions['budget.depense.valider']);
        if ($saisit && $valide && !$derogation) {
            return ProblemResponseFactory::creer(422, 'Cumul interdit', 'Ces rôles réunis permettraient à la même personne de saisir et de valider le budget. Seuls les rôles Administrateur et Responsable suivi-évaluation peuvent cumuler les deux.');
        }

        $courant = $this->security->getUser();
        if ($courant instanceof Utilisateur && $courant->getId() === $utilisateur->getId() && !\in_array('administrateur', $codes, true)) {
            foreach ($utilisateur->getAttributionsRoles() as $attribution) {
                if ('administrateur' === $attribution->getRole()->getCode()) {
                    return ProblemResponseFactory::creer(422, 'Action refusée', 'Vous ne pouvez pas retirer votre propre rôle d\'administrateur.');
                }
            }
        }

        // Différence avec l'existant : on retire ce qui n'est plus voulu, on
        // ajoute ce qui manque — une attribution inchangée n'est pas réécrite.
        foreach ($utilisateur->getAttributionsRoles()->toArray() as $attribution) {
            $cle = $attribution->getRole()->getCode().'|'.($attribution->getStructure()?->getId() ?? '');
            if (isset($voulus[$cle])) {
                unset($voulus[$cle]);
                continue;
            }
            $utilisateur->retirerAttributionRole($attribution);
            $this->em->remove($attribution);
        }
        foreach ($voulus as [$role, $structure]) {
            $this->em->persist(new UtilisateurRole($utilisateur, $role, $structure));
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($utilisateur));
    }

    #[Route('/{uuid}', name: 'app_utilisateur_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimer(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.utilisateurs');

        $utilisateur = $this->trouver($uuid);
        $utilisateur->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    private function trouver(string $uuid): Utilisateur
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $utilisateur = null !== $uuidObjet ? $this->utilisateurs->findOneBy(['uuid' => $uuidObjet]) : null;

        return $utilisateur ?? throw new NotFoundHttpException('Compte utilisateur introuvable.');
    }
}
