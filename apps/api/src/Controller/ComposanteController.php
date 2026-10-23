<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerComposanteRequest;
use App\Entity\Composante;
use App\Http\ComposanteNormalizer;
use App\Http\ProblemResponseFactory;
use App\Repository\ComposanteRepository;
use App\Repository\ProjetRepository;
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
 * Les composantes restent visibles de tous (§ 0, décision actée avec
 * l'ANSD : "la sous-composante/composante reste visible dans l'arbre").
 * Leur création/modification reste réservée aux détenteurs globaux de
 * activites.creer/modifier/supprimer — jamais satisfaite par une
 * attribution scopée à une structure (§ 7.2).
 */
#[Route('/api/v1/composantes')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ComposanteController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly ComposanteRepository $composantes,
        private readonly ProjetRepository $projets,
        private readonly StructureRepository $structures,
        private readonly ComposanteNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_composante_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        $projet = null;
        $projetUuid = $request->query->get('projet');
        if (\is_string($projetUuid)) {
            $projet = $this->projets->findOneBy(['uuid' => $this->uuidOuNull($projetUuid)]);
        }

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $this->composantes->listerNonSupprimees($projet)),
        ]);
    }

    #[Route('/{uuid}', name: 'app_composante_detail', methods: ['GET'])]
    public function detail(string $uuid): JsonResponse
    {
        return new JsonResponse($this->normalizer->normaliser($this->trouver($uuid)));
    }

    #[Route('', name: 'app_composante_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'activites.creer');

        /** @var CreerComposanteRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerComposanteRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $projetUuid = $this->uuidOuNull($request->query->get('projet') ?? '');
        $projet = null !== $projetUuid ? $this->projets->findOneBy(['uuid' => $projetUuid]) : $this->projets->findOneBy([]);
        if (null === $projet) {
            return ProblemResponseFactory::creer(422, 'Projet introuvable');
        }

        $composante = new Composante($projet, $donnees->code, $donnees->libelle);
        $composante->setDescription($donnees->description);
        $composante->setOrdre($donnees->ordre);
        $this->resoudreStructurePilote($composante, $donnees->structurePilote);

        $this->em->persist($composante);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($composante), 201);
    }

    #[Route('/{uuid}', name: 'app_composante_modifier', methods: ['PATCH'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $composante = $this->trouver($uuid);
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'activites.modifier');

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('code', $donnees) && \is_string($donnees['code'])) {
            $composante->setCode($donnees['code']);
        }
        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle'])) {
            $composante->setLibelle($donnees['libelle']);
        }
        if (\array_key_exists('description', $donnees)) {
            $composante->setDescription(\is_string($donnees['description']) ? $donnees['description'] : null);
        }
        if (\array_key_exists('ordre', $donnees) && \is_int($donnees['ordre'])) {
            $composante->setOrdre($donnees['ordre']);
        }
        if (\array_key_exists('structurePilote', $donnees)) {
            $this->resoudreStructurePilote($composante, \is_string($donnees['structurePilote']) ? $donnees['structurePilote'] : null);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($composante));
    }

    #[Route('/{uuid}', name: 'app_composante_supprimer', methods: ['DELETE'])]
    public function supprimer(string $uuid): Response
    {
        $composante = $this->trouver($uuid);
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'activites.supprimer');

        // Archiver une composante qui contient encore des sous-composantes
        // ferait disparaître leur budget de l'arbre sans le dire.
        $enfants = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM sous_composantes WHERE composante_id = ? AND supprime_le IS NULL',
            [$composante->getId()],
        );
        if ($enfants > 0) {
            return ProblemResponseFactory::creer(409, 'Composante non vide', \sprintf('Cette composante contient encore %d sous-composante(s) : archivez-les ou déplacez-les d\'abord.', $enfants));
        }

        $composante->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    private function resoudreStructurePilote(Composante $composante, ?string $structureUuid): void
    {
        if (null === $structureUuid) {
            $composante->setStructurePilote(null);

            return;
        }

        $uuid = $this->uuidOuNull($structureUuid);
        $structure = null !== $uuid ? $this->structures->findOneBy(['uuid' => $uuid]) : null;
        $composante->setStructurePilote($structure);
    }

    private function trouver(string $uuid): Composante
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $composante = null !== $uuidObjet ? $this->composantes->findOneBy(['uuid' => $uuidObjet]) : null;

        return $composante ?? throw new NotFoundHttpException('Composante introuvable.');
    }
}
