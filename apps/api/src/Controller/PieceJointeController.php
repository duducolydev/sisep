<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Http\ProblemResponseFactory;
use App\Repository\PieceJointeRepository;
use App\Security\PieceJointeAccesResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Téléchargement d'une pièce jointe, quelle que soit l'entité polymorphe à laquelle elle est rattachée — accès arbitré par PieceJointeAccesResolver selon sa visibilité. */
#[Route('/api/v1')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PieceJointeController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly PieceJointeRepository $piecesJointes,
        private readonly PieceJointeAccesResolver $accesResolver,
        private readonly Security $security,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('/pieces-jointes/{uuid}/telecharger', name: 'app_piece_jointe_telecharger', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function telecharger(string $uuid): Response
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $piece = null !== $uuidObjet ? $this->piecesJointes->findOneBy(['uuid' => $uuidObjet]) : null;
        if (null === $piece) {
            return ProblemResponseFactory::creer(404, 'Pièce jointe introuvable');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        if (!$this->accesResolver->estAccessible($piece, $utilisateur)) {
            throw new AccessDeniedException();
        }

        $chemin = $this->projectDir.'/'.$piece->getChemin();
        if (!is_file($chemin)) {
            return ProblemResponseFactory::creer(404, 'Fichier introuvable', 'Le fichier a été déplacé ou supprimé du disque.');
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', $piece->getMime());
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $piece->getNomFichier());

        return $reponse;
    }
}
