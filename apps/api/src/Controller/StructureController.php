<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerStructureRequest;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Enum\TypeStructure;
use App\Http\ActiviteNormalizer;
use App\Http\ProblemResponseFactory;
use App\Http\StructureNormalizer;
use App\Repository\ActiviteRepository;
use App\Repository\StructureRepository;
use App\Security\PermissionsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * CRUD complet des structures (§ 2.5, § 2.6, § 10 — P6). Lecture ouverte à
 * tous les authentifiés dès P2 pour les filtres ; écriture réservée à
 * structures.gerer. Aucun rôle du référentiel de démonstration ne détient
 * cette permission avec une portée scopée (seul le coordonnateur, en
 * portée globale), mais le contrôleur reste portée-aware (isGranted avec
 * l'entité comme sujet) plutôt que figé sur exigerPermissionGlobale, pour
 * rester cohérent si une attribution scopée est créée plus tard (§ 7.2).
 */
#[Route('/api/v1/structures')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class StructureController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly StructureRepository $structures,
        private readonly ActiviteRepository $activites,
        private readonly StructureNormalizer $normalizer,
        private readonly ActiviteNormalizer $activiteNormalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_structure_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        // ?toutes=1 réservé à l'administration (§ 2.6) : les structures
        // désactivées ne doivent pas polluer les filtres et formulaires
        // ordinaires, seulement l'écran de gestion qui doit pouvoir les
        // réactiver.
        $utilisateur = $this->security->getUser();
        $toutes = '1' === $request->query->get('toutes')
            && $utilisateur instanceof Utilisateur
            && $this->permissionsResolver->pour($utilisateur)->estGlobale('structures.gerer');

        $structures = $toutes ? $this->structures->listerNonSupprimees() : $this->structures->listerActives();

        return new JsonResponse(['donnees' => array_map($this->normalizer->normaliser(...), $structures)]);
    }

    #[Route('/{uuid}', name: 'app_structure_detail', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function detail(string $uuid): JsonResponse
    {
        return new JsonResponse($this->normalizer->normaliser($this->trouver($uuid)));
    }

    #[Route('/{uuid}/activites', name: 'app_structure_activites', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function activites(string $uuid): JsonResponse
    {
        $structure = $this->trouver($uuid);

        // Le filtre SQL de portée (§ 7.2) s'applique automatiquement à
        // ActiviteRepository — un point focal DSID appelant cette route pour
        // une autre structure reçoit une liste vide, pas 403 : cohérent avec
        // le choix déjà fait pour /projets/:uuid/arbre (transparence sur les
        // totaux, jamais sur le détail nommé hors périmètre).
        $activites = $this->activites->findBy(['structureResponsable' => $structure, 'supprimeLe' => null], ['code' => 'ASC']);

        return new JsonResponse(['donnees' => array_map($this->activiteNormalizer->normaliser(...), $activites)]);
    }

    #[Route('', name: 'app_structure_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'structures.gerer');

        /** @var CreerStructureRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerStructureRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $type = TypeStructure::tryFrom($donnees->type);
        if (null === $type) {
            return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'type de structure inconnu.');
        }

        $structure = new Structure($donnees->sigle, $donnees->denomination, $type);
        $structure->setTelephone($donnees->telephone);
        $structure->setEmail($donnees->email);
        $structure->setAdresse($donnees->adresse);

        $this->em->persist($structure);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($structure), 201);
    }

    #[Route('/{uuid}', name: 'app_structure_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $structure = $this->trouver($uuid);
        $this->exigerPermission($this->security, 'structures.gerer', $structure);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('sigle', $donnees) && \is_string($donnees['sigle']) && '' !== $donnees['sigle']) {
            $structure->setSigle($donnees['sigle']);
        }
        if (\array_key_exists('denomination', $donnees) && \is_string($donnees['denomination']) && '' !== $donnees['denomination']) {
            $structure->setDenomination($donnees['denomination']);
        }
        if (\array_key_exists('type', $donnees) && \is_string($donnees['type'])) {
            $type = TypeStructure::tryFrom($donnees['type']);
            if (null === $type) {
                return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'type de structure inconnu.');
            }
            $structure->setType($type);
        }
        if (\array_key_exists('telephone', $donnees)) {
            $structure->setTelephone(\is_string($donnees['telephone']) ? $donnees['telephone'] : null);
        }
        if (\array_key_exists('email', $donnees)) {
            if (!\is_string($donnees['email']) || '' === $donnees['email'] || !filter_var($donnees['email'], \FILTER_VALIDATE_EMAIL)) {
                return ProblemResponseFactory::creer(422, 'Données invalides', "L'email est requis pour recevoir les alertes et doit être valide.");
            }
            $structure->setEmail($donnees['email']);
        }
        if (\array_key_exists('adresse', $donnees)) {
            $structure->setAdresse(\is_string($donnees['adresse']) ? $donnees['adresse'] : null);
        }
        if (\array_key_exists('actif', $donnees) && \is_bool($donnees['actif'])) {
            $structure->setActif($donnees['actif']);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($structure));
    }

    #[Route('/{uuid}', name: 'app_structure_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimer(string $uuid): Response
    {
        $structure = $this->trouver($uuid);
        $this->exigerPermission($this->security, 'structures.gerer', $structure);

        $structure->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    private function trouver(string $uuid): Structure
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $structure = null !== $uuidObjet ? $this->structures->findOneBy(['uuid' => $uuidObjet]) : null;

        return $structure ?? throw new NotFoundHttpException('Structure introuvable.');
    }
}
