<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Http\ProblemResponseFactory;
use App\Http\TypeDocumentRequete;
use App\Repository\PieceJointeRepository;
use App\Security\PermissionsResolver;
use App\Security\PieceJointeAccesResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Téléchargement d'une pièce jointe, quelle que soit l'entité polymorphe à
 * laquelle elle est rattachée — accès arbitré par PieceJointeAccesResolver
 * selon sa visibilité — et correction de son intitulé ou de son type.
 */
#[Route('/api/v1')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PieceJointeController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly PieceJointeRepository $piecesJointes,
        private readonly PieceJointeAccesResolver $accesResolver,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * Formats ouverts dans le navigateur avec `?apercu=1` (consulter un
     * rapport transmis sans le télécharger). Jamais de HTML ni de SVG : un
     * fichier déposé s'y exécuterait sous l'origine de SISEP.
     */
    private const APERCU_POSSIBLE = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    #[Route('/pieces-jointes/{uuid}/telecharger', name: 'app_piece_jointe_telecharger', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function telecharger(string $uuid, Request $request): Response
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
        // Le type déclaré au dépôt fait foi : le navigateur ne le devine pas.
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $apercu = $request->query->getBoolean('apercu') && \in_array($piece->getMime(), self::APERCU_POSSIBLE, true);
        $reponse->setContentDisposition($apercu ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $piece->getNomFichier());

        return $reponse;
    }

    /**
     * Corriger l'intitulé ou le type d'un document déjà déposé (présentation
     * au PHASAOC, octobre 2026) — sans le retirer ni le redéposer, ce qui
     * ferait perdre sa date et son auteur. Le fichier lui-même ne change pas.
     *
     * Qui peut : la personne qui l'a déposé, ou l'UGP (`documents.gerer` sur
     * tout le projet). Vaut pour toute pièce jointe — document de
     * bibliothèque comme justificatif d'étape ou de valeur d'indicateur.
     * Corps JSON, champs facultatifs : `libelle` (vide ou null : retour au
     * nom de fichier), `typeDocument` et `typeDocumentPrecision`.
     */
    #[Route('/pieces-jointes/{uuid}', name: 'app_piece_jointe_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifier(string $uuid, Request $request): Response
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
        $estDeposant = $piece->getCreePar()->getUuid()->equals($utilisateur->getUuid());
        if (!$estDeposant && !$this->permissionsResolver->pour($utilisateur)->estGlobale('documents.gerer')) {
            throw new AccessDeniedException();
        }

        $donnees = json_decode($request->getContent(), true);
        if (!\is_array($donnees)) {
            return ProblemResponseFactory::creer(400, 'Corps JSON invalide');
        }

        if (\array_key_exists('typeDocument', $donnees)) {
            $nature = TypeDocumentRequete::depuis($donnees);
            if ($nature instanceof JsonResponse) {
                return $nature;
            }
            $piece->qualifier(...$nature);
        }

        if (\array_key_exists('libelle', $donnees)) {
            $libelle = $donnees['libelle'];
            if (null !== $libelle && !\is_string($libelle)) {
                return ProblemResponseFactory::creer(422, 'Intitulé invalide');
            }
            $libelle = null !== $libelle ? trim($libelle) : '';
            if (mb_strlen($libelle) > 255) {
                return ProblemResponseFactory::creer(422, 'Intitulé trop long', "L'intitulé tient en 255 caractères au plus.");
            }
            $piece->setLibelle('' !== $libelle ? $libelle : null);
        }

        $this->em->flush();

        return new JsonResponse([
            'uuid' => $piece->getUuid()->toRfc4122(),
            'libelle' => $piece->getLibelle(),
            'nomFichier' => $piece->getNomFichier(),
            'typeDocument' => $piece->getTypeDocument()?->value,
            'typeDocumentPrecision' => $piece->getTypeDocumentPrecision(),
            'typeDocumentLibelle' => $piece->libelleTypeDocument(),
        ]);
    }
}
