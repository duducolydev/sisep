<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerSousComposanteRequest;
use App\Entity\SousComposante;
use App\Http\ProblemResponseFactory;
use App\Http\SousComposanteNormalizer;
use App\Repository\ComposanteRepository;
use App\Repository\SousComposanteRepository;
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

#[Route('/api/v1/sous-composantes')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class SousComposanteController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly SousComposanteRepository $sousComposantes,
        private readonly ComposanteRepository $composantes,
        private readonly StructureRepository $structures,
        private readonly SousComposanteNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_sous_composante_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        $composante = null;
        $composanteUuid = $request->query->get('composante');
        if (\is_string($composanteUuid)) {
            $composante = $this->composantes->findOneBy(['uuid' => $this->uuidOuNull($composanteUuid)]);
        }

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $this->sousComposantes->listerNonSupprimees($composante)),
        ]);
    }

    #[Route('/{uuid}', name: 'app_sous_composante_detail', methods: ['GET'])]
    public function detail(string $uuid): JsonResponse
    {
        return new JsonResponse($this->normalizer->normaliser($this->trouver($uuid)));
    }

    #[Route('', name: 'app_sous_composante_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'activites.creer');

        /** @var CreerSousComposanteRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerSousComposanteRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $composanteUuid = $this->uuidOuNull($donnees->composante);
        $composante = null !== $composanteUuid ? $this->composantes->findOneBy(['uuid' => $composanteUuid]) : null;
        if (null === $composante) {
            return ProblemResponseFactory::creer(422, 'Composante introuvable');
        }

        $sousComposante = new SousComposante($composante, $donnees->code, $donnees->libelle);
        $sousComposante->setDescription($donnees->description);
        $sousComposante->setOrdre($donnees->ordre);
        $this->resoudreStructurePilote($sousComposante, $donnees->structurePilote);

        $this->em->persist($sousComposante);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($sousComposante), 201);
    }

    #[Route('/{uuid}', name: 'app_sous_composante_modifier', methods: ['PATCH'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $sousComposante = $this->trouver($uuid);
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'activites.modifier');

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('code', $donnees) && \is_string($donnees['code'])) {
            $sousComposante->setCode($donnees['code']);
        }
        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle'])) {
            $sousComposante->setLibelle($donnees['libelle']);
        }
        if (\array_key_exists('description', $donnees)) {
            $sousComposante->setDescription(\is_string($donnees['description']) ? $donnees['description'] : null);
        }
        if (\array_key_exists('ordre', $donnees) && \is_int($donnees['ordre'])) {
            $sousComposante->setOrdre($donnees['ordre']);
        }
        if (\array_key_exists('structurePilote', $donnees)) {
            $this->resoudreStructurePilote($sousComposante, \is_string($donnees['structurePilote']) ? $donnees['structurePilote'] : null);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($sousComposante));
    }

    #[Route('/{uuid}', name: 'app_sous_composante_supprimer', methods: ['DELETE'])]
    public function supprimer(string $uuid): Response
    {
        $sousComposante = $this->trouver($uuid);
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'activites.supprimer');

        $enfants = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM activites WHERE sous_composante_id = ? AND supprime_le IS NULL',
            [$sousComposante->getId()],
        );
        if ($enfants > 0) {
            return ProblemResponseFactory::creer(409, 'Sous-composante non vide', \sprintf('Cette sous-composante contient encore %d activité(s) : archivez-les d\'abord.', $enfants));
        }

        $sousComposante->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    private function resoudreStructurePilote(SousComposante $sousComposante, ?string $structureUuid): void
    {
        if (null === $structureUuid) {
            $sousComposante->setStructurePilote(null);

            return;
        }

        $uuid = $this->uuidOuNull($structureUuid);
        $structure = null !== $uuid ? $this->structures->findOneBy(['uuid' => $uuid]) : null;
        $sousComposante->setStructurePilote($structure);
    }

    private function trouver(string $uuid): SousComposante
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $sousComposante = null !== $uuidObjet ? $this->sousComposantes->findOneBy(['uuid' => $uuidObjet]) : null;

        return $sousComposante ?? throw new NotFoundHttpException('Sous-composante introuvable.');
    }
}
