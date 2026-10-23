<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerPointFocalRequest;
use App\Entity\PointFocal;
use App\Http\PointFocalNormalizer;
use App\Http\ProblemResponseFactory;
use App\Repository\PointFocalRepository;
use App\Repository\StructureRepository;
use App\Repository\UtilisateurRepository;
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
 * points_focaux.gerer (§ 7.2) est détenue à la fois globalement
 * (coordonnateur) et scopée par structure (point_focal_structure) : chaque
 * action passe par isGranted avec l'entité comme sujet, jamais
 * exigerPermissionGlobale.
 */
#[Route('/api/v1/points-focaux')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PointFocalController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly PointFocalRepository $pointsFocaux,
        private readonly StructureRepository $structures,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly PointFocalNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_point_focal_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        $criteres = [];
        $structureUuid = $request->query->get('structure');
        if (\is_string($structureUuid)) {
            $structure = $this->structures->findOneBy(['uuid' => $this->uuidOuNull($structureUuid)]);
            if (null === $structure) {
                return new JsonResponse(['donnees' => []]);
            }
            $criteres['structure'] = $structure;
        }

        $pointsFocaux = $this->pointsFocaux->findBy($criteres, ['nomComplet' => 'ASC']);

        return new JsonResponse(['donnees' => array_map($this->normalizer->normaliser(...), $pointsFocaux)]);
    }

    #[Route('', name: 'app_point_focal_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        /** @var CreerPointFocalRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerPointFocalRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $structureUuid = $this->uuidOuNull($donnees->structure);
        $structure = null !== $structureUuid ? $this->structures->findOneBy(['uuid' => $structureUuid]) : null;
        if (null === $structure) {
            return ProblemResponseFactory::creer(422, 'Structure introuvable');
        }

        $this->exigerPermission($this->security, 'points_focaux.gerer', $structure);

        $pointFocal = new PointFocal($structure, $donnees->nomComplet, $donnees->fonction);
        $pointFocal->setTelephone($donnees->telephone);
        $pointFocal->setEmail($donnees->email);
        $pointFocal->setPrincipal($donnees->principal);
        if (null !== $donnees->dateDesignation) {
            $pointFocal->setDateDesignation(new \DateTimeImmutable($donnees->dateDesignation));
        }

        $this->em->persist($pointFocal);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($pointFocal), 201);
    }

    #[Route('/{uuid}', name: 'app_point_focal_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $pointFocal = $this->trouver($uuid);
        $this->exigerPermission($this->security, 'points_focaux.gerer', $pointFocal);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('nomComplet', $donnees) && \is_string($donnees['nomComplet']) && '' !== $donnees['nomComplet']) {
            $pointFocal->setNomComplet($donnees['nomComplet']);
        }
        if (\array_key_exists('fonction', $donnees) && \is_string($donnees['fonction']) && '' !== $donnees['fonction']) {
            $pointFocal->setFonction($donnees['fonction']);
        }
        if (\array_key_exists('telephone', $donnees)) {
            $pointFocal->setTelephone(\is_string($donnees['telephone']) ? $donnees['telephone'] : null);
        }
        if (\array_key_exists('email', $donnees)) {
            $pointFocal->setEmail(\is_string($donnees['email']) ? $donnees['email'] : null);
        }
        if (\array_key_exists('principal', $donnees) && \is_bool($donnees['principal'])) {
            $pointFocal->setPrincipal($donnees['principal']);
        }
        if (\array_key_exists('actif', $donnees) && \is_bool($donnees['actif'])) {
            $pointFocal->setActif($donnees['actif']);
        }
        if (\array_key_exists('dateDesignation', $donnees)) {
            $pointFocal->setDateDesignation(\is_string($donnees['dateDesignation']) && '' !== $donnees['dateDesignation'] ? new \DateTimeImmutable($donnees['dateDesignation']) : null);
        }
        if (\array_key_exists('utilisateurUuid', $donnees)) {
            if (null === $donnees['utilisateurUuid']) {
                $pointFocal->setUtilisateur(null);
            } elseif (\is_string($donnees['utilisateurUuid'])) {
                $utilisateurUuid = $this->uuidOuNull($donnees['utilisateurUuid']);
                $utilisateur = null !== $utilisateurUuid ? $this->utilisateurs->findOneBy(['uuid' => $utilisateurUuid]) : null;
                if (null === $utilisateur) {
                    return ProblemResponseFactory::creer(422, 'Données invalides', 'Compte utilisateur introuvable.');
                }
                $pointFocal->setUtilisateur($utilisateur);
            }
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($pointFocal));
    }

    private function trouver(string $uuid): PointFocal
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $pointFocal = null !== $uuidObjet ? $this->pointsFocaux->findOneBy(['uuid' => $uuidObjet]) : null;

        return $pointFocal ?? throw new NotFoundHttpException('Point focal introuvable.');
    }
}
