<?php

declare(strict_types=1);

namespace App\Controller;

use App\Budget\DepenseValidationService;
use App\Dto\CreerDepenseRequest;
use App\Entity\Depense;
use App\Entity\Utilisateur;
use App\Enum\StatutEcriture;
use App\Exception\ContrainteBudgetaireException;
use App\Http\DepenseNormalizer;
use App\Http\ProblemResponseFactory;
use App\Repository\DepenseRepository;
use App\Repository\EngagementRepository;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/depenses')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DepenseController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly DepenseRepository $depenses,
        private readonly EngagementRepository $engagements,
        private readonly DepenseNormalizer $normalizer,
        private readonly DepenseValidationService $validationService,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_depense_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        /** @var CreerDepenseRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerDepenseRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $engagementUuid = $this->uuidOuNull($donnees->engagement);
        $engagement = null !== $engagementUuid ? $this->engagements->findOneBy(['uuid' => $engagementUuid]) : null;
        if (null === $engagement) {
            return ProblemResponseFactory::creer(422, 'Engagement introuvable');
        }

        if (!$this->security->isGranted('budget.depense.saisir', $engagement->getCible())) {
            throw new AccessDeniedException();
        }

        if (!$engagement->getExercice()->estOuvert()) {
            return ProblemResponseFactory::creer(422, "Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }

        $depense = new Depense(
            $engagement,
            Montant::fromFcfa((int) $donnees->montant),
            new \DateTimeImmutable($donnees->dateDepense),
            $utilisateur,
        );
        $depense->setReferencePaiement($donnees->referencePaiement);

        $this->em->persist($depense);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($depense), 201);
    }

    /**
     * Validation en lot des dépenses — même conduite que pour les
     * engagements (EngagementController::validerLot()). Chaque dépense passe par les mêmes contrôles que la
     * validation unitaire (droit sur sa ligne, séparation saisie/validation,
     * plafond sous verrou) ; ceux qui seraient refusés sont écartés avec leur
     * motif, les autres validés un à un.
     */
    #[Route('/valider-lot', name: 'app_depense_valider_lot', methods: ['POST'])]
    public function validerLot(Request $request): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }
        $this->exigerPermission($this->security, 'budget.depense.valider');

        $uuids = $this->uuidsDuLot($request, 'depenses');
        if ($uuids instanceof JsonResponse) {
            return $uuids;
        }

        $validees = 0;
        $refus = [];
        foreach ($uuids as $position => $uuid) {
            $uuidObjet = $this->uuidOuNull($uuid);
            $depense = null !== $uuidObjet ? $this->depenses->findOneBy(['uuid' => $uuidObjet]) : null;
            if (null === $depense || $depense->estSupprime()) {
                $refus[] = ['uuid' => $uuid, 'reference' => null, 'motif' => 'Dépense introuvable.'];

                continue;
            }
            $reference = $depense->getReferencePaiement() ?? ($depense->getEngagement()->getReferenceMarche() ?? $depense->getEngagement()->getActiviteParente()->getCode());
            if (!$this->security->isGranted('budget.depense.valider', $depense->getEngagement()->getCible())) {
                $refus[] = ['uuid' => $uuid, 'reference' => $reference, 'motif' => 'Hors de votre périmètre de validation.'];

                continue;
            }
            $motif = $this->validationService->motifDeRefus($depense, $utilisateur);
            if (null !== $motif) {
                $refus[] = ['uuid' => $uuid, 'reference' => $reference, 'motif' => $motif];

                continue;
            }

            try {
                $this->validationService->valider($depense, $utilisateur);
                ++$validees;
            } catch (ContrainteBudgetaireException $e) {
                // Une saisie concurrente entre le contrôle et le verrou : la
                // transaction annulée ferme le gestionnaire d'entités. Le
                // reste du lot attend un nouvel essai, rien n'est perdu.
                $refus[] = ['uuid' => $uuid, 'reference' => $reference, 'motif' => $e->getMessage()];
                foreach (\array_slice($uuids, $position + 1) as $restant) {
                    $refus[] = ['uuid' => $restant, 'reference' => null, 'motif' => 'Non traité : relancez la validation.'];
                }

                break;
            }
        }

        return new JsonResponse(['validees' => $validees, 'refus' => $refus]);
    }

    #[Route('/{uuid}/valider', name: 'app_depense_valider', methods: ['POST'])]
    public function valider(string $uuid): JsonResponse
    {
        $depense = $this->trouver($uuid);

        if (!$this->security->isGranted('budget.depense.valider', $depense->getEngagement()->getCible())) {
            throw new AccessDeniedException();
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }

        $this->validationService->valider($depense, $utilisateur);

        return new JsonResponse($this->normalizer->normaliser($depense));
    }

    /**
     * Rejet d'une dépense saisie, symétrique de l'annulation d'un
     * engagement : sans lui, une dépense erronée ou au-delà de l'engagement
     * — que la validation refuse — resterait en brouillon sans issue, la
     * saisie n'étant pas modifiable. Suppression logique par le statut,
     * jamais physique.
     */
    #[Route('/{uuid}/annuler', name: 'app_depense_annuler', methods: ['POST'])]
    public function annuler(string $uuid): JsonResponse
    {
        $depense = $this->trouver($uuid);

        if (!$this->security->isGranted('budget.depense.valider', $depense->getEngagement()->getCible())) {
            throw new AccessDeniedException();
        }

        if (StatutEcriture::BROUILLON !== $depense->getStatut()) {
            throw new ContrainteBudgetaireException('Seule une dépense en brouillon peut être annulée.');
        }

        $depense->annuler();
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($depense));
    }

    private function trouver(string $uuid): Depense
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $depense = null !== $uuidObjet ? $this->depenses->findOneBy(['uuid' => $uuidObjet]) : null;

        return $depense ?? throw new NotFoundHttpException('Dépense introuvable.');
    }
}
