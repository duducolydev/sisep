<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\JournalActivite;
use App\Http\JournalActiviteNormalizer;
use App\Http\ReferenceEcritureBudgetaireResolver;
use App\Repository\JournalActiviteRepository;
use App\Repository\UtilisateurRepository;
use App\Security\PermissionsResolver;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Onglet "Journal d'activité" (§ 2.6, § 6.2, § 7.3) — journal.consulter, portée globale uniquement. */
#[Route('/api/v1/journal')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class JournalController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly JournalActiviteRepository $journal,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly JournalActiviteNormalizer $normalizer,
        private readonly ReferenceEcritureBudgetaireResolver $references,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    #[Route('', name: 'app_journal_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'journal.consulter');

        $qb = $this->journal->createQueryBuilder('j')->orderBy('j.creeLe', 'DESC');

        $entite = $request->query->get('entite');
        if (\is_string($entite) && '' !== $entite) {
            $qb->andWhere('j.entiteType = :entite')->setParameter('entite', $entite);
        }

        if (null !== ($erreur = $this->appliquerFiltresCommuns($qb, $request))) {
            return $erreur;
        }

        $page = max(1, $request->query->getInt('page', 1));
        $taille = min(100, max(1, $request->query->getInt('taille', 25)));

        $total = (int) (clone $qb)->select('COUNT(j.id)')->getQuery()->getSingleScalarResult();
        $entrees = $qb->setFirstResult(($page - 1) * $taille)->setMaxResults($taille)->getQuery()->getResult();

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $entrees),
            'pagination' => $this->pagination($page, $taille, $total),
        ]);
    }

    /**
     * Fil des écritures budgétaires — dotations, engagements, dépenses.
     *
     * C'est la contrepartie de la dérogation accordée au responsable
     * suivi-évaluation, qui cumule saisie et validation du budget (§ 7.2) :
     * la séparation des rôles ne tient plus, la traçabilité doit donc être
     * consultable là où le budget se saisit, et pas seulement dans l'onglet
     * d'administration. Même permission qu'ailleurs (journal.consulter,
     * portée globale) : lire qui a validé une dépense n'est pas un droit
     * d'écran, c'est un droit d'audit.
     *
     * Les entrées sont enrichies d'une référence lisible (activité, exercice,
     * montant) plutôt que du seul couple type + id.
     */
    #[Route('/budget', name: 'app_journal_budget', methods: ['GET'])]
    public function budget(Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'journal.consulter');

        $qb = $this->journal->createQueryBuilder('j')->orderBy('j.creeLe', 'DESC');

        // Un seul type demandé : on restreint ; sinon les trois, jamais tout
        // le journal — cette vue ne doit pas devenir une porte sur les
        // écritures de droits ou de comptes.
        $type = $request->query->get('type');
        $types = \is_string($type) && \in_array($type, ReferenceEcritureBudgetaireResolver::TYPES, true)
            ? [$type]
            : ReferenceEcritureBudgetaireResolver::TYPES;
        $qb->andWhere('j.entiteType IN (:types)')->setParameter('types', $types);

        if (null !== ($erreur = $this->appliquerFiltresCommuns($qb, $request))) {
            return $erreur;
        }

        $page = max(1, $request->query->getInt('page', 1));
        $taille = min(100, max(1, $request->query->getInt('taille', 25)));

        $total = (int) (clone $qb)->select('COUNT(j.id)')->getQuery()->getSingleScalarResult();
        /** @var list<JournalActivite> $entrees */
        $entrees = $qb->setFirstResult(($page - 1) * $taille)->setMaxResults($taille)->getQuery()->getResult();
        $references = $this->references->pour($entrees);

        return new JsonResponse([
            'donnees' => array_map(
                fn (JournalActivite $e) => [
                    ...$this->normalizer->normaliser($e),
                    'reference' => $references[$e->getEntiteType().':'.$e->getEntiteId()] ?? null,
                ],
                $entrees,
            ),
            'pagination' => $this->pagination($page, $taille, $total),
        ]);
    }

    /**
     * Filtres partagés par les deux vues : auteur et fenêtre de dates.
     *
     * Renvoie une réponse vide plutôt que 404 quand l'uuid d'auteur ne
     * correspond à personne : un filtre qui ne désigne personne ne ramène
     * rien, ce n'est pas une erreur de la requête.
     */
    private function appliquerFiltresCommuns(QueryBuilder $qb, Request $request): ?JsonResponse
    {
        $utilisateurUuid = $request->query->get('utilisateur');
        if (\is_string($utilisateurUuid) && '' !== $utilisateurUuid) {
            $utilisateur = $this->utilisateurs->findOneBy(['uuid' => $this->uuidOuNull($utilisateurUuid)]);
            if (null === $utilisateur) {
                return new JsonResponse(['donnees' => [], 'pagination' => $this->pagination(1, 25, 0)]);
            }
            $qb->andWhere('j.utilisateur = :utilisateur')->setParameter('utilisateur', $utilisateur);
        }

        $du = $request->query->get('du');
        if (\is_string($du) && '' !== $du) {
            $qb->andWhere('j.creeLe >= :du')->setParameter('du', new \DateTimeImmutable($du));
        }
        $au = $request->query->get('au');
        if (\is_string($au) && '' !== $au) {
            $qb->andWhere('j.creeLe <= :au')->setParameter('au', new \DateTimeImmutable($au.' 23:59:59'));
        }

        return null;
    }

    /** @return array{page: int, taille: int, total: int, pages: int} */
    private function pagination(int $page, int $taille, int $total): array
    {
        return ['page' => $page, 'taille' => $taille, 'total' => $total, 'pages' => (int) max(1, ceil($total / $taille))];
    }
}
