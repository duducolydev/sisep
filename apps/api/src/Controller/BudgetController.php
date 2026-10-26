<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Depense;
use App\Entity\Engagement;
use App\Entity\SousActivite;
use App\Entity\Utilisateur;
use App\Http\ProblemResponseFactory;
use App\Query\BudgetExecutionReader;
use App\Repository\ComposanteRepository;
use App\Repository\DepenseRepository;
use App\Repository\EngagementRepository;
use App\Repository\ExerciceRepository;
use App\Repository\ProjetRepository;
use App\Security\PermissionsResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/budget')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class BudgetController
{
    use ApiControllerHelpersTrait;

    private const NIVEAUX_VALIDES = ['composante', 'sous_composante', 'activite'];

    /** Au-delà, l'écran en affiche le nombre et invite à valider par lots successifs. */
    private const LIMITE_A_VALIDER = 500;

    public function __construct(
        private readonly ProjetRepository $projets,
        private readonly ExerciceRepository $exercices,
        private readonly ComposanteRepository $composantes,
        private readonly BudgetExecutionReader $reader,
        private readonly EngagementRepository $engagements,
        private readonly DepenseRepository $depenses,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    /**
     * GET /budget/a-valider — engagements et dépenses en brouillon, pour la
     * validation en lot (après un import notamment). Chaque écriture dit qui
     * l'a saisie : l'écran ne propose pas à quelqu'un de valider la sienne.
     */
    #[Route('/a-valider', name: 'app_budget_a_valider', methods: ['GET'])]
    public function aValider(): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }
        $permissions = $this->permissionsResolver->pour($utilisateur);
        $engagements = $permissions->possede('budget.engagement.valider');
        $depenses = $permissions->possede('budget.depense.valider');
        if (!$engagements && !$depenses) {
            throw new AccessDeniedException();
        }

        return new JsonResponse([
            'limite' => self::LIMITE_A_VALIDER,
            'engagements' => $engagements ? array_map($this->engagement(...), $this->engagements->brouillons(self::LIMITE_A_VALIDER)) : [],
            'depenses' => $depenses ? array_map($this->depense(...), $this->depenses->brouillons(self::LIMITE_A_VALIDER)) : [],
        ]);
    }

    /** @return array<string, mixed> */
    private function engagement(Engagement $e): array
    {
        return [
            'uuid' => $e->getUuid()->toRfc4122(),
            'reference' => $e->getReferenceMarche(),
            'nature' => $e->getNature()->value,
            'montant' => $e->getMontant()->versFcfa(),
            'date' => $e->getDateEngagement()->format('Y-m-d'),
            'exercice' => $e->getExercice()->getAnnee(),
            ...$this->ligne($e),
            'saisiPar' => ['uuid' => $e->getCreePar()->getUuid()->toRfc4122(), 'nomComplet' => $e->getCreePar()->getNomComplet()],
        ];
    }

    /** @return array<string, mixed> */
    private function depense(Depense $d): array
    {
        $engagement = $d->getEngagement();

        return [
            'uuid' => $d->getUuid()->toRfc4122(),
            'referencePaiement' => $d->getReferencePaiement(),
            'montant' => $d->getMontant()->versFcfa(),
            'date' => $d->getDateDepense()->format('Y-m-d'),
            'engagement' => [
                'reference' => $engagement->getReferenceMarche(),
                'statut' => $engagement->getStatut()->value,
                'montant' => $engagement->getMontant()->versFcfa(),
            ],
            ...$this->ligne($engagement),
            'saisiPar' => ['uuid' => $d->getCreePar()->getUuid()->toRfc4122(), 'nomComplet' => $d->getCreePar()->getNomComplet()],
        ];
    }

    /** @return array{activite: array{uuid: string, code: string}, ligne: string} */
    private function ligne(Engagement $engagement): array
    {
        $activite = $engagement->getActiviteParente();
        $cible = $engagement->getCible();

        return [
            'activite' => ['uuid' => $activite->getUuid()->toRfc4122(), 'code' => $activite->getCode()],
            'ligne' => $cible instanceof SousActivite ? $cible->getLibelle() : $activite->getLibelle(),
        ];
    }

    /** GET /budget/execution?exercice=&composante=&niveau=activite|sous_composante|composante — § 6.2 du brief. */
    #[Route('/execution', name: 'app_budget_execution', methods: ['GET'])]
    public function execution(Request $request): Response
    {
        $niveau = $request->query->get('niveau', 'activite');
        if (!\in_array($niveau, self::NIVEAUX_VALIDES, true)) {
            return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'niveau doit valoir activite, sous_composante ou composante.');
        }

        $projet = $this->projets->findOneBy([]);
        if (null === $projet) {
            return ProblemResponseFactory::creer(422, 'Projet introuvable');
        }

        $annee = $request->query->get('exercice');
        $exercice = null !== $annee
            ? $this->exercices->trouverParAnnee($projet, (int) $annee)
            : $this->exercices->courantOuPlusRecent($projet);
        if (null === $exercice) {
            return ProblemResponseFactory::creer(422, 'Exercice introuvable', 'Aucun exercice ne correspond à ce projet.');
        }

        $composanteId = null;
        $composanteUuid = $request->query->get('composante');
        if (\is_string($composanteUuid)) {
            $composante = $this->composantes->findOneBy(['uuid' => $this->uuidOuNull($composanteUuid)]);
            if (null === $composante) {
                return ProblemResponseFactory::creer(422, 'Composante introuvable');
            }
            $composanteId = $composante->getId();
        }

        return new JsonResponse([
            'exercice' => $exercice->getAnnee(),
            'niveau' => $niveau,
            'donnees' => $this->reader->lire((int) $projet->getId(), (int) $exercice->getId(), $niveau, $composanteId),
        ]);
    }
}
