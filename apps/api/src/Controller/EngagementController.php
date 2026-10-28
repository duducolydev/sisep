<?php

declare(strict_types=1);

namespace App\Controller;

use App\Budget\EngagementValidationService;
use App\Dto\CreerEngagementRequest;
use App\Entity\Activite;
use App\Entity\Engagement;
use App\Entity\Utilisateur;
use App\Enum\NatureEngagement;
use App\Enum\StatutEcriture;
use App\Exception\ContrainteBudgetaireException;
use App\Http\EngagementNormalizer;
use App\Http\ProblemResponseFactory;
use App\Repository\ActiviteRepository;
use App\Repository\EngagementRepository;
use App\Repository\ExerciceRepository;
use App\Repository\SousActiviteRepository;
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

#[Route('/api/v1/engagements')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class EngagementController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly EngagementRepository $engagements,
        private readonly ActiviteRepository $activites,
        private readonly SousActiviteRepository $sousActivites,
        private readonly ExerciceRepository $exercices,
        private readonly EngagementNormalizer $normalizer,
        private readonly EngagementValidationService $validationService,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_engagement_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        // Les engagements se lisent avec les activités qui les portent : même
        // droit, et la portée par structure suit (cf. EngagementRepository).
        $this->exigerPermission($this->security, 'activites.consulter');

        $page = max(1, $request->query->getInt('page', 1));
        $taille = min(100, max(1, $request->query->getInt('taille', 25)));

        $cible = null;
        $activiteUuid = $request->query->get('activite');
        $sousActiviteUuid = $request->query->get('sousActivite');
        if (\is_string($activiteUuid)) {
            $cible = $this->activites->findOneBy(['uuid' => $this->uuidOuNull($activiteUuid)]);
        } elseif (\is_string($sousActiviteUuid)) {
            $cible = $this->sousActivites->findOneBy(['uuid' => $this->uuidOuNull($sousActiviteUuid)]);
        }

        $exercice = null;
        $annee = $request->query->get('exercice');
        if (\is_string($annee) && null !== $cible) {
            $activiteParente = $cible instanceof Activite ? $cible : $cible->getActivite();
            $exercice = $this->exercices->trouverParAnnee($activiteParente->getSousComposante()->getComposante()->getProjet(), (int) $annee);
        }

        $statut = null;
        $statutBrut = $request->query->get('statut');
        if (\is_string($statutBrut)) {
            $statut = StatutEcriture::tryFrom($statutBrut);
        }

        $resultat = $this->engagements->filtrer($cible, $exercice, $statut, $page, $taille);

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $resultat['donnees']),
            'pagination' => [
                'page' => $page,
                'taille' => $taille,
                'total' => $resultat['total'],
                'pages' => (int) ceil($resultat['total'] / $taille),
            ],
        ]);
    }

    #[Route('', name: 'app_engagement_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        /** @var CreerEngagementRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerEngagementRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $cible = $this->resoudreCibleBudgetaire($donnees->activite, $donnees->sousActivite, $this->activites, $this->sousActivites);
        if ($cible instanceof JsonResponse) {
            return $cible;
        }

        if (!$this->security->isGranted('budget.engagement.saisir', $cible)) {
            throw new AccessDeniedException();
        }

        $nature = NatureEngagement::tryFrom($donnees->nature);
        if (null === $nature) {
            return ProblemResponseFactory::creer(422, 'Nature d\'engagement invalide');
        }

        $activiteParente = $cible instanceof Activite ? $cible : $cible->getActivite();
        $projet = $activiteParente->getSousComposante()->getComposante()->getProjet();
        $exercice = $this->exercices->trouverParAnnee($projet, (int) $donnees->exercice);
        if (null === $exercice) {
            return ProblemResponseFactory::creer(422, 'Exercice introuvable pour ce projet');
        }
        if (!$exercice->estOuvert()) {
            return ProblemResponseFactory::creer(422, "Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }

        $engagement = new Engagement(
            $cible,
            $exercice,
            $nature,
            Montant::fromFcfa((int) $donnees->montant),
            new \DateTimeImmutable($donnees->dateEngagement),
            $utilisateur,
        );
        $engagement->setReferenceMarche($donnees->referenceMarche);
        $engagement->setObservations($donnees->observations);

        // Le RSE (budget.validation_directe) n'a pas besoin d'être validé :
        // son engagement l'est dès la saisie, plafond contrôlé sous verrou.
        if ($this->validationService->valideDirectement($utilisateur) && $this->security->isGranted('budget.engagement.valider', $cible)) {
            $this->validationService->enregistrerValide($engagement, $utilisateur);
        } else {
            $this->em->persist($engagement);
            $this->em->flush();
        }

        return new JsonResponse($this->normalizer->normaliser($engagement), 201);
    }

    /**
     * Validation en lot — après un import, des dizaines d'engagements
     * attendent. Chaque engagement passe par les mêmes contrôles que la
     * validation unitaire (droit sur sa ligne, séparation saisie/validation,
     * plafond sous verrou) ; ceux qui seraient refusés sont écartés avec leur
     * motif, les autres validés un à un.
     */
    #[Route('/valider-lot', name: 'app_engagement_valider_lot', methods: ['POST'])]
    public function validerLot(Request $request): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }
        $this->exigerPermission($this->security, 'budget.engagement.valider');

        $uuids = $this->uuidsDuLot($request, 'engagements');
        if ($uuids instanceof JsonResponse) {
            return $uuids;
        }

        $validees = 0;
        $refus = [];
        foreach ($uuids as $position => $uuid) {
            $uuidObjet = $this->uuidOuNull($uuid);
            $engagement = null !== $uuidObjet ? $this->engagements->findOneBy(['uuid' => $uuidObjet]) : null;
            if (null === $engagement || $engagement->estSupprime()) {
                $refus[] = ['uuid' => $uuid, 'reference' => null, 'motif' => 'Engagement introuvable.'];

                continue;
            }
            $reference = $engagement->getReferenceMarche() ?? $engagement->getActiviteParente()->getCode();
            if (!$this->security->isGranted('budget.engagement.valider', $engagement->getCible())) {
                $refus[] = ['uuid' => $uuid, 'reference' => $reference, 'motif' => 'Hors de votre périmètre de validation.'];

                continue;
            }
            $motif = $this->validationService->motifDeRefus($engagement, $utilisateur);
            if (null !== $motif) {
                $refus[] = ['uuid' => $uuid, 'reference' => $reference, 'motif' => $motif];

                continue;
            }

            try {
                $this->validationService->valider($engagement, $utilisateur);
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

    #[Route('/{uuid}/valider', name: 'app_engagement_valider', methods: ['POST'])]
    public function valider(string $uuid): JsonResponse
    {
        $engagement = $this->trouver($uuid);

        if (!$this->security->isGranted('budget.engagement.valider', $engagement->getCible())) {
            throw new AccessDeniedException();
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }

        $this->validationService->valider($engagement, $utilisateur);

        return new JsonResponse($this->normalizer->normaliser($engagement));
    }

    #[Route('/{uuid}/annuler', name: 'app_engagement_annuler', methods: ['POST'])]
    public function annuler(string $uuid): JsonResponse
    {
        $engagement = $this->trouver($uuid);

        if (!$this->security->isGranted('budget.engagement.valider', $engagement->getCible())) {
            throw new AccessDeniedException();
        }

        if (StatutEcriture::BROUILLON !== $engagement->getStatut()) {
            throw new ContrainteBudgetaireException('Seul un engagement en brouillon peut être annulé.');
        }

        $engagement->annuler();
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($engagement));
    }

    private function trouver(string $uuid): Engagement
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $engagement = null !== $uuidObjet ? $this->engagements->findOneBy(['uuid' => $uuidObjet]) : null;

        return $engagement ?? throw new NotFoundHttpException('Engagement introuvable.');
    }
}
