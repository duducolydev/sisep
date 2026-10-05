<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ProblemResponseFactory;
use App\Query\BudgetExecutionReader;
use App\Repository\ComposanteRepository;
use App\Repository\ExerciceRepository;
use App\Repository\ProjetRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/budget')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class BudgetController
{
    use ApiControllerHelpersTrait;

    private const NIVEAUX_VALIDES = ['composante', 'sous_composante', 'activite'];

    public function __construct(
        private readonly ProjetRepository $projets,
        private readonly ExerciceRepository $exercices,
        private readonly ComposanteRepository $composantes,
        private readonly BudgetExecutionReader $reader,
    ) {
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
            : $this->exercices->findOneBy(['projet' => $projet], ['annee' => 'DESC']);
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
