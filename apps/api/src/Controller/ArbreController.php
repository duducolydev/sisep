<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Exercice;
use App\Entity\Projet;
use App\Http\ProblemResponseFactory;
use App\Query\ActiviteRecenteReader;
use App\Query\AlertesReader;
use App\Query\ArbreReader;
use App\Query\DecaissementReader;
use App\Query\ExecutionPluriannuelleReader;
use App\Query\ExecutionSyntheseReader;
use App\Query\SyntheseReader;
use App\Repository\ExerciceRepository;
use App\Repository\ProjetRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/projets')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ArbreController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly ProjetRepository $projets,
        private readonly ExerciceRepository $exercices,
        private readonly ArbreReader $arbreReader,
        private readonly DecaissementReader $decaissementReader,
        private readonly SyntheseReader $syntheseReader,
        private readonly AlertesReader $alertesReader,
        private readonly ActiviteRecenteReader $activiteRecenteReader,
        private readonly ExecutionPluriannuelleReader $executionPluriannuelleReader,
        private readonly ExecutionSyntheseReader $executionSyntheseReader,
    ) {
    }

    /** Résout le projet depuis l'uuid ou lève 404 — factorisé, utilisé par toutes les actions de ce contrôleur. */
    private function projetOuErreur(string $uuid): Projet
    {
        $projetUuid = $this->uuidOuNull($uuid);
        $projet = null !== $projetUuid ? $this->projets->findOneBy(['uuid' => $projetUuid]) : null;
        if (null === $projet) {
            throw new NotFoundHttpException('Projet introuvable.');
        }

        return $projet;
    }

    /** Résout l'exercice demandé (ou celui de l'année en cours) — factorisé, utilisé par arbre/décaissement/synthèse/alertes. */
    private function exerciceOuErreur(Projet $projet, Request $request): Exercice|Response
    {
        $annee = $request->query->get('exercice');
        $exercice = null !== $annee
            ? $this->exercices->trouverParAnnee($projet, (int) $annee)
            : $this->exercices->courantOuPlusRecent($projet);

        if (null === $exercice) {
            return ProblemResponseFactory::creer(422, 'Exercice introuvable', 'Aucun exercice ne correspond à ce projet.');
        }

        return $exercice;
    }

    /**
     * Tentant de paginer — ne pas le faire : l'arbre complet fait quelques
     * dizaines de kilo-octets et l'IHM en a besoin d'un bloc pour le
     * filtrage instantané (§ 6.2 du brief).
     */
    /**
     * Exécution physique et financière (retours des RSE, octobre 2026).
     * Période : l'exercice entier, ou un trimestre (`trimestre=1..4`), ou un
     * mois (`mois=1..12`) de cet exercice. Filtres : `composante`, `activite`
     * (UUID) — le détail descend d'un niveau sous la sélection.
     */
    #[Route('/{uuid}/execution', name: 'app_projet_execution', methods: ['GET'])]
    public function execution(string $uuid, Request $request): Response
    {
        $projet = $this->projetOuErreur($uuid);
        $exercice = $this->exerciceOuErreur($projet, $request);
        if ($exercice instanceof Response) {
            return $exercice;
        }

        $annee = $exercice->getAnnee();
        $debut = $exercice->getDateDebut();
        $fin = $exercice->getDateFin();
        $trimestre = $request->query->get('trimestre');
        $mois = $request->query->get('mois');
        if (null !== $trimestre && '' !== $trimestre) {
            $t = (int) $trimestre;
            if ($t < 1 || $t > 4) {
                return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'trimestre doit valoir 1, 2, 3 ou 4.');
            }
            $debut = new \DateTimeImmutable(\sprintf('%d-%02d-01', $annee, ($t - 1) * 3 + 1));
            $fin = $debut->modify('+3 months -1 day');
        } elseif (null !== $mois && '' !== $mois) {
            $m = (int) $mois;
            if ($m < 1 || $m > 12) {
                return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'mois doit valoir de 1 à 12.');
            }
            $debut = new \DateTimeImmutable(\sprintf('%d-%02d-01', $annee, $m));
            $fin = $debut->modify('last day of this month');
        }

        $composante = $this->uuidOuNull((string) $request->query->get('composante', ''))?->toRfc4122();
        $activite = $this->uuidOuNull((string) $request->query->get('activite', ''))?->toRfc4122();

        return new JsonResponse([
            'exercice' => $annee,
            ...$this->executionSyntheseReader->lire((int) $projet->getId(), (int) $exercice->getId(), $debut, $fin, $composante, $activite),
        ]);
    }

    #[Route('/{uuid}/arbre', name: 'app_projet_arbre', methods: ['GET'])]
    public function arbre(string $uuid, Request $request): Response
    {
        $projet = $this->projetOuErreur($uuid);

        $niveaux = $request->query->getInt('niveaux', 4);
        if (!\in_array($niveaux, [3, 4], true)) {
            return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'niveaux doit valoir 3 ou 4.');
        }

        $exercice = $this->exerciceOuErreur($projet, $request);
        if ($exercice instanceof Response) {
            return $exercice;
        }

        return new JsonResponse([
            'exercice' => $exercice->getAnnee(),
            'niveaux' => $niveaux,
            'tauxAttendu' => $exercice->tauxAttendu(new \DateTimeImmutable('today')),
            'composantes' => $this->arbreReader->lire((int) $projet->getId(), (int) $exercice->getId(), $niveaux),
        ]);
    }

    /** GET /projets/:uuid/decaissement?exercice= — série mensuelle réalisée + trajectoire (§ 6.2, § 2.1, § 2.3). */
    #[Route('/{uuid}/decaissement', name: 'app_projet_decaissement', methods: ['GET'])]
    public function decaissement(string $uuid, Request $request): Response
    {
        $projet = $this->projetOuErreur($uuid);

        $exercice = $this->exerciceOuErreur($projet, $request);
        if ($exercice instanceof Response) {
            return $exercice;
        }

        return new JsonResponse([
            'exercice' => $exercice->getAnnee(),
            'points' => $this->decaissementReader->lire(
                (int) $projet->getId(),
                (int) $exercice->getId(),
                $exercice->getDateDebut(),
                $exercice->getDateFin(),
            ),
        ]);
    }

    /** GET /projets/:uuid/synthese?exercice= — les quatre cartes du tableau de bord (§ 2.1, § 6.2). */
    #[Route('/{uuid}/synthese', name: 'app_projet_synthese', methods: ['GET'])]
    public function synthese(string $uuid, Request $request): Response
    {
        $projet = $this->projetOuErreur($uuid);

        $exercice = $this->exerciceOuErreur($projet, $request);
        if ($exercice instanceof Response) {
            return $exercice;
        }

        return new JsonResponse([
            'exercice' => $exercice->getAnnee(),
            ...$this->syntheseReader->lire((int) $projet->getId(), (int) $exercice->getId()),
        ]);
    }

    /**
     * GET /projets/:uuid/alertes?exercice= — points d'attention (§ 2.1, § 5.3).
     * Recalculées à chaque appel depuis les vues d'exécution et les seuils
     * courants, jamais stockées (critère d'acceptation P5).
     */
    #[Route('/{uuid}/alertes', name: 'app_projet_alertes', methods: ['GET'])]
    public function alertes(string $uuid, Request $request): Response
    {
        $projet = $this->projetOuErreur($uuid);

        $exercice = $this->exerciceOuErreur($projet, $request);
        if ($exercice instanceof Response) {
            return $exercice;
        }

        return new JsonResponse([
            'exercice' => $exercice->getAnnee(),
            'alertes' => $this->alertesReader->lire((int) $projet->getId(), $exercice),
        ]);
    }

    /**
     * GET /projets/:uuid/execution-pluriannuelle — montants/taux d'exécution
     * (dotations notifiées vs dépenses validées) par exercice et en cumul
     * global, tout le projet, tous exercices confondus — pas un seul comme
     * /decaissement. Lecture ouverte à tout authentifié, même convention
     * que les autres agrégats de ce contrôleur.
     */
    #[Route('/{uuid}/execution-pluriannuelle', name: 'app_projet_execution_pluriannuelle', methods: ['GET'])]
    public function executionPluriannuelle(string $uuid): Response
    {
        $projet = $this->projetOuErreur($uuid);

        return new JsonResponse($this->executionPluriannuelleReader->lire((int) $projet->getId()));
    }

    /** GET /projets/:uuid/activite-recente — fil des dernières écritures, toutes structures confondues (§ 2.1). */
    #[Route('/{uuid}/activite-recente', name: 'app_projet_activite_recente', methods: ['GET'])]
    public function activiteRecente(string $uuid): Response
    {
        $projet = $this->projetOuErreur($uuid);

        return new JsonResponse([
            'evenements' => $this->activiteRecenteReader->lire((int) $projet->getId()),
        ]);
    }

    /** GET /projets/:uuid/exercices — peuple le sélecteur d'année du tableau de bord (§ décision filtres période). */
    #[Route('/{uuid}/exercices', name: 'app_projet_exercices', methods: ['GET'])]
    public function exercices(string $uuid): Response
    {
        $projet = $this->projetOuErreur($uuid);

        return new JsonResponse([
            'donnees' => array_map(static fn (Exercice $e) => [
                'annee' => $e->getAnnee(),
                'statut' => $e->getStatut()->value,
            ], $this->exercices->lister($projet)),
        ]);
    }
}
