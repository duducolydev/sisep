<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerActiviteRequest;
use App\Entity\Activite;
use App\Entity\Exercice;
use App\Enum\StatutActivite;
use App\Http\ActiviteNormalizer;
use App\Http\DotationNormalizer;
use App\Http\EngagementNormalizer;
use App\Http\ProblemResponseFactory;
use App\Http\SousActiviteNormalizer;
use App\Repository\ActiviteRepository;
use App\Repository\ExerciceRepository;
use App\Repository\PieceJointeRepository;
use App\Repository\SousComposanteRepository;
use App\Repository\StructureRepository;
use Doctrine\DBAL\Connection;
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

#[Route('/api/v1/activites')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ActiviteController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly ActiviteRepository $activites,
        private readonly SousComposanteRepository $sousComposantes,
        private readonly StructureRepository $structures,
        private readonly PieceJointeRepository $piecesJointes,
        private readonly ActiviteNormalizer $normalizer,
        private readonly SousActiviteNormalizer $sousActiviteNormalizer,
        private readonly DotationNormalizer $dotationNormalizer,
        private readonly EngagementNormalizer $engagementNormalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly ExerciceRepository $exercices,
        private readonly Connection $connection,
    ) {
    }

    #[Route('', name: 'app_activite_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $taille = min(100, max(1, $request->query->getInt('taille', 25)));

        $resultat = $this->activites->paginer($page, $taille);

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

    /** Fiche complète : dotations, engagements (et leurs dépenses imbriquées), sous-activités, pièces jointes (§ 6.2). */
    #[Route('/{uuid}', name: 'app_activite_detail', methods: ['GET'])]
    public function detail(string $uuid, Request $request): JsonResponse
    {
        $activite = $this->trouver($uuid);

        if (!$this->security->isGranted('activites.consulter', $activite)) {
            throw new AccessDeniedException();
        }

        return new JsonResponse($this->normaliserFicheComplete($activite, $request));
    }

    #[Route('', name: 'app_activite_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        /** @var CreerActiviteRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerActiviteRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $sousComposanteUuid = $this->uuidOuNull($donnees->sousComposante);
        $sousComposante = null !== $sousComposanteUuid ? $this->sousComposantes->findOneBy(['uuid' => $sousComposanteUuid]) : null;
        if (null === $sousComposante) {
            return ProblemResponseFactory::creer(422, 'Sous-composante introuvable');
        }

        $structureUuid = $this->uuidOuNull($donnees->structureResponsable);
        $structure = null !== $structureUuid ? $this->structures->findOneBy(['uuid' => $structureUuid]) : null;
        if (null === $structure) {
            return ProblemResponseFactory::creer(422, 'Structure responsable introuvable');
        }

        $activite = new Activite($sousComposante, $donnees->code, $donnees->libelle, $structure);
        $activite->setDescription($donnees->description);
        $activite->setOrdre($donnees->ordre);
        if (null !== $donnees->dateDebut) {
            $activite->setDateDebut(new \DateTimeImmutable($donnees->dateDebut));
        }
        if (null !== $donnees->dateFinPrevue) {
            $activite->setDateFinPrevue(new \DateTimeImmutable($donnees->dateFinPrevue));
        }

        // Construite avant vérification : la portée de activites.creer se
        // juge sur la structure responsable visée, pas dans l'absolu (§ 7.2).
        if (!$this->security->isGranted('activites.creer', $activite)) {
            throw new AccessDeniedException();
        }

        $this->em->persist($activite);
        $this->em->flush();

        return new JsonResponse($this->normaliserFicheComplete($activite, $request), 201);
    }

    #[Route('/{uuid}', name: 'app_activite_modifier', methods: ['PATCH'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $activite = $this->trouver($uuid);

        if (!$this->security->isGranted('activites.modifier', $activite)) {
            throw new AccessDeniedException();
        }

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('code', $donnees) && \is_string($donnees['code'])) {
            $activite->setCode($donnees['code']);
        }

        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle'])) {
            $activite->setLibelle($donnees['libelle']);
        }

        if (\array_key_exists('description', $donnees)) {
            $activite->setDescription(\is_string($donnees['description']) ? $donnees['description'] : null);
        }

        if (\array_key_exists('statut', $donnees) && \is_string($donnees['statut'])) {
            $statut = StatutActivite::tryFrom($donnees['statut']);
            if (null === $statut) {
                return new JsonResponse(['title' => 'Statut invalide', 'status' => 422], 422);
            }
            // Clôturer une activité est une validation, distincte de sa
            // modification courante (§ 7.2, même séparation que
            // budget.*.valider/indicateurs.valider) : quiconque saisit
            // l'avancement ne peut pas se l'auto-valider.
            if (StatutActivite::TERMINEE === $statut && !$this->security->isGranted('activites.valider', $activite)) {
                throw new AccessDeniedException();
            }
            $activite->setStatut($statut);
        }

        $this->em->flush();

        return new JsonResponse($this->normaliserFicheComplete($activite, $request));
    }

    #[Route('/{uuid}', name: 'app_activite_supprimer', methods: ['DELETE'])]
    public function supprimer(string $uuid): Response
    {
        $activite = $this->trouver($uuid);

        if (!$this->security->isGranted('activites.supprimer', $activite)) {
            throw new AccessDeniedException();
        }

        $activite->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /** @return array<string, mixed> */
    private function normaliserFicheComplete(Activite $activite, Request $request): array
    {
        $piecesJointes = null === $activite->getId()
            ? []
            : $this->piecesJointes->findBy(['entiteType' => 'activite', 'entiteId' => $activite->getId()]);

        $exercice = $this->resoudreExercice($activite, $request);
        $sousActivites = $activite->getSousActivitesActives()->toArray();

        return [
            ...$this->normalizer->normaliser($activite),
            ...$this->executionPour('activite_id', $activite->getId(), $exercice),
            'sousActivites' => array_map(
                fn ($sa) => [
                    ...$this->sousActiviteNormalizer->normaliser($sa),
                    ...$this->executionPour('sous_activite_id', $sa->getId(), $exercice),
                    'dotations' => array_map($this->dotationNormalizer->normaliser(...), $sa->getDotations()->toArray()),
                    'engagements' => array_map($this->engagementNormalizer->normaliser(...), $sa->getEngagements()->toArray()),
                ],
                $sousActivites,
            ),
            'dotations' => array_map($this->dotationNormalizer->normaliser(...), $activite->getDotations()->toArray()),
            'engagements' => array_map($this->engagementNormalizer->normaliser(...), $activite->getEngagements()->toArray()),
            'piecesJointes' => array_map(
                static fn ($p) => ['uuid' => $p->getUuid()->toRfc4122(), 'nomFichier' => $p->getNomFichier(), 'mime' => $p->getMime()],
                $piecesJointes,
            ),
        ];
    }

    /** `?exercice=AAAA` cible un exercice précis, sinon le plus récent du projet. */
    private function resoudreExercice(Activite $activite, Request $request): ?Exercice
    {
        $projet = $activite->getSousComposante()->getComposante()->getProjet();
        $annee = $request->query->get('exercice');

        return null !== $annee
            ? $this->exercices->findOneBy(['projet' => $projet, 'annee' => (int) $annee])
            : $this->exercices->findOneBy(['projet' => $projet], ['annee' => 'DESC']);
    }

    /**
     * Notifié/engagé/dépensé de l'exercice (§ 5.1, § 6.2) — jamais stockés,
     * lus depuis les vues `v_execution_activite`/`v_execution_sous_activite`
     * comme le fait l'arbre d'exécution.
     *
     * @return array{notifie: int|null, engage: int|null, depense: int|null}
     */
    private function executionPour(string $colonneCible, ?int $id, ?Exercice $exercice): array
    {
        if (null === $id || null === $exercice) {
            return ['notifie' => null, 'engage' => null, 'depense' => null];
        }

        $vue = 'activite_id' === $colonneCible ? 'v_execution_activite' : 'v_execution_sous_activite';
        $ligne = $this->connection->fetchAssociative(
            "SELECT notifie, engage, depense FROM {$vue} WHERE {$colonneCible} = ? AND exercice_id = ?",
            [$id, $exercice->getId()],
        );

        return [
            'notifie' => false !== $ligne ? (int) $ligne['notifie'] : null,
            'engage' => false !== $ligne ? (int) $ligne['engage'] : null,
            'depense' => false !== $ligne ? (int) $ligne['depense'] : null,
        ];
    }

    private function trouver(string $uuid): Activite
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $activite = null !== $uuidObjet ? $this->activites->findOneBy(['uuid' => $uuidObjet]) : null;

        return $activite ?? throw new NotFoundHttpException('Activité introuvable.');
    }
}
