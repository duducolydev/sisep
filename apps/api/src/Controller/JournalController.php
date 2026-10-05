<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JournalActiviteNormalizer;
use App\Repository\JournalActiviteRepository;
use App\Repository\UtilisateurRepository;
use App\Security\PermissionsResolver;
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

        $page = max(1, $request->query->getInt('page', 1));
        $taille = min(100, max(1, $request->query->getInt('taille', 25)));

        $total = (int) (clone $qb)->select('COUNT(j.id)')->getQuery()->getSingleScalarResult();
        $entrees = $qb->setFirstResult(($page - 1) * $taille)->setMaxResults($taille)->getQuery()->getResult();

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $entrees),
            'pagination' => $this->pagination($page, $taille, $total),
        ]);
    }

    /** @return array{page: int, taille: int, total: int, pages: int} */
    private function pagination(int $page, int $taille, int $total): array
    {
        return ['page' => $page, 'taille' => $taille, 'total' => $total, 'pages' => (int) max(1, ceil($total / $taille))];
    }
}
