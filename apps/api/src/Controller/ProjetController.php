<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProjetRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le multi-projet est dans le schéma mais pas dans l'IHM en v1 (§ 11 du
 * brief) : pas de sélecteur, donc pas de liste. Le front a quand même besoin
 * d'un point d'entrée pour découvrir l'uuid de l'unique projet actif.
 */
#[Route('/api/v1/projets')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ProjetController
{
    public function __construct(private readonly ProjetRepository $projets)
    {
    }

    #[Route('/courant', name: 'app_projet_courant', methods: ['GET'])]
    public function courant(): JsonResponse
    {
        $projet = $this->projets->findOneBy(['actif' => true]) ?? $this->projets->findOneBy([]);
        if (null === $projet) {
            throw new NotFoundHttpException('Aucun projet configuré.');
        }

        return new JsonResponse([
            'uuid' => $projet->getUuid()->toRfc4122(),
            'code' => $projet->getCode(),
            'libelle' => $projet->getLibelle(),
        ]);
    }
}
