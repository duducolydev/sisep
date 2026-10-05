<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerGabaritEtapesRequest;
use App\Entity\EtapeGabarit;
use App\Entity\GabaritEtapes;
use App\Http\GabaritEtapesNormalizer;
use App\Http\ProblemResponseFactory;
use App\Repository\GabaritEtapesRepository;
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
 * Référentiel des gabarits d'étapes (§ 6 du CR du 22/09/2026) — défini par
 * l'UGP seule : admin.parametres, en portée globale, n'est détenue que par le
 * coordonnateur et le responsable suivi-évaluation. C'est bien un
 * paramétrage du suivi, au même titre que les seuils de vigilance, et non une
 * donnée d'activité : un point focal ne normalise pas la méthode de suivi du
 * projet.
 *
 * La lecture reste ouverte à tout authentifié : le catalogue sert aussi à
 * afficher les étapes d'une activité, que n'importe quel lecteur peut voir.
 */
#[Route('/api/v1/gabarits-etapes')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class GabaritEtapesController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly GabaritEtapesRepository $gabarits,
        private readonly GabaritEtapesNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_gabarit_etapes_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        $gabarits = $request->query->getBoolean('tous')
            ? $this->gabarits->listerNonSupprimes()
            : $this->gabarits->listerActifs();

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $gabarits),
        ]);
    }

    #[Route('/{uuid}', name: 'app_gabarit_etapes_detail', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function detail(string $uuid): JsonResponse
    {
        return new JsonResponse($this->normalizer->normaliser($this->trouver($uuid)));
    }

    #[Route('', name: 'app_gabarit_etapes_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.parametres');

        /** @var CreerGabaritEtapesRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerGabaritEtapesRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $gabarit = new GabaritEtapes($donnees->libelle);
        $gabarit->setDescription($donnees->description);
        $this->em->persist($gabarit);

        foreach ($donnees->etapes as $rang => $libelle) {
            $this->em->persist(new EtapeGabarit($gabarit, $libelle, $rang + 1));
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($gabarit), 201);
    }

    /**
     * Remplace la liste des étapes quand "etapes" est fourni : c'est un
     * référentiel, pas un suivi — les activités déjà instanciées gardent leur
     * copie et ne bougent pas (cf. GabaritEtapes).
     */
    #[Route('/{uuid}', name: 'app_gabarit_etapes_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifier(string $uuid, Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.parametres');
        $gabarit = $this->trouver($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle']) && '' !== $donnees['libelle']) {
            $gabarit->setLibelle($donnees['libelle']);
        }
        if (\array_key_exists('description', $donnees)) {
            $gabarit->setDescription(\is_string($donnees['description']) ? $donnees['description'] : null);
        }
        if (\array_key_exists('actif', $donnees) && \is_bool($donnees['actif'])) {
            $gabarit->setActif($donnees['actif']);
        }

        if (\array_key_exists('etapes', $donnees)) {
            if (!\is_array($donnees['etapes']) || [] === $donnees['etapes']) {
                return ProblemResponseFactory::creer(422, 'Données invalides', 'Un gabarit doit comporter au moins une étape.');
            }
            foreach ($donnees['etapes'] as $libelle) {
                if (!\is_string($libelle) || '' === trim($libelle)) {
                    return ProblemResponseFactory::creer(422, 'Données invalides', 'Chaque étape doit porter un libellé.');
                }
            }

            foreach ($gabarit->getEtapes() as $ancienne) {
                $this->em->remove($ancienne);
            }
            $gabarit->getEtapes()->clear();
            foreach (array_values($donnees['etapes']) as $rang => $libelle) {
                $this->em->persist(new EtapeGabarit($gabarit, $libelle, $rang + 1));
            }
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($gabarit));
    }

    /** Suppression logique : un gabarit retiré du catalogue reste lisible dans l'historique des activités qu'il a servi à structurer. */
    #[Route('/{uuid}', name: 'app_gabarit_etapes_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimer(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'admin.parametres');

        $gabarit = $this->trouver($uuid);
        $gabarit->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    private function trouver(string $uuid): GabaritEtapes
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $gabarit = null !== $uuidObjet ? $this->gabarits->findOneBy(['uuid' => $uuidObjet]) : null;

        if (null === $gabarit || $gabarit->estSupprime()) {
            throw new NotFoundHttpException('Gabarit introuvable.');
        }

        return $gabarit;
    }
}
