<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Composante;
use App\Entity\PieceJointe;
use App\Entity\Projet;
use App\Entity\SousComposante;
use App\Entity\Utilisateur;
use App\Enum\FamilleDocument;
use App\Enum\TypeDocument;
use App\Enum\VisibilitePieceJointe;
use App\Http\ProblemResponseFactory;
use App\Http\TypeDocumentRequete;
use App\Repository\ComposanteRepository;
use App\Repository\PieceJointeRepository;
use App\Repository\ProjetRepository;
use App\Repository\SousComposanteRepository;
use App\Security\PermissionsResolver;
use App\Security\PieceJointeAccesResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Bibliothèque documentaire de l'UGP (§ 12 du CR du 22/09/2026) et documents
 * de cadrage rattachés aux composantes et sous-composantes.
 *
 * Trois niveaux de rattachement, un seul mécanisme : l'entité polymorphe
 * PieceJointe, déjà utilisée pour les justificatifs d'étapes, de valeurs
 * d'indicateurs et de rapports. Rien de nouveau côté stockage, seulement
 * trois nouveaux `entiteType`.
 *
 * Deux droits distincts, et c'est volontaire :
 *  - déposer ou retirer exige `documents.gerer` — l'UGP tient sa
 *    bibliothèque, une structure bénéficiaire n'y verse rien ;
 *  - consulter n'exige aucune permission particulière : c'est la visibilité
 *    de chaque document (tous / équipe PHASAOC / sectoriels / agents
 *    nommés) qui décide, arbitrée par PieceJointeAccesResolver — le même
 *    arbitre qu'au téléchargement, pour que la liste ne montre jamais un
 *    document que le téléchargement refuserait.
 *
 * Le retrait est physique, fichier compris : une bibliothèque sans retrait
 * se remplit de brouillons et devient inutilisable. Ce n'est pas un journal
 * d'audit — les écritures budgétaires, elles, restent en suppression
 * logique.
 */
#[Route('/api/v1')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DocumentController
{
    use ApiControllerHelpersTrait;

    /**
     * 50 Mo : une présentation d'atelier dépasse vite les 10 Mo des
     * justificatifs (retours des RSE, fichier refusé de 28 Mo). Doit rester
     * sous client_max_body_size (nginx) et post_max_size (PHP).
     */
    public const TAILLE_MAX_OCTETS = 50 * 1024 * 1024;

    /** Segment d'URL => entiteType stocké. */
    private const TYPES = [
        'projets' => 'projet',
        'composantes' => 'composante',
        'sous-composantes' => 'sous_composante',
    ];

    public function __construct(
        private readonly PieceJointeRepository $piecesJointes,
        private readonly ProjetRepository $projets,
        private readonly ComposanteRepository $composantes,
        private readonly SousComposanteRepository $sousComposantes,
        private readonly PieceJointeAccesResolver $accesResolver,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route(
        '/documents/{type}/{uuid}',
        name: 'app_documents_liste',
        methods: ['GET'],
        requirements: ['type' => 'projets|composantes|sous-composantes', 'uuid' => '[0-9a-fA-F-]{36}'],
    )]
    public function liste(string $type, string $uuid, Request $request): Response
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        $cible = $this->resoudreCible($type, $uuid);
        if (null === $cible) {
            return ProblemResponseFactory::creer(404, 'Entité introuvable');
        }

        $documents = $this->piecesJointes->filtrer(
            self::TYPES[$type],
            (int) $cible->getId(),
            $this->chaineOuNull($request, 'q'),
            $this->dateOuNull($request, 'du'),
            // Borne haute inclusive : « au 6 octobre » doit retenir un
            // document déposé ce jour-là à 15 h.
            $this->dateOuNull($request, 'au')?->setTime(23, 59, 59),
            FamilleDocument::tryFrom((string) $request->query->get('famille', '')),
            TypeDocument::tryFrom((string) $request->query->get('type', '')),
        );

        return new JsonResponse([
            'donnees' => array_values(array_map(
                $this->normaliser(...),
                // Le filtre de visibilité reste en PHP : il dépend des rôles
                // du lecteur, pas d'une colonne. Sur le volume d'une
                // bibliothèque de projet, c'est sans conséquence.
                array_filter($documents, fn (PieceJointe $p) => $this->accesResolver->estAccessible($p, $utilisateur)),
            )),
        ]);
    }

    private function chaineOuNull(Request $requete, string $parametre): ?string
    {
        $valeur = $requete->query->get($parametre);

        return \is_string($valeur) && '' !== trim($valeur) ? trim($valeur) : null;
    }

    private function dateOuNull(Request $requete, string $parametre): ?\DateTimeImmutable
    {
        $valeur = $this->chaineOuNull($requete, $parametre);
        if (null === $valeur) {
            return null;
        }

        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            // Une date illisible ne filtre rien plutôt que de renvoyer une
            // erreur : le paramètre vient d'un champ de formulaire, pas d'un
            // contrat d'API.
            return null;
        }
    }

    #[Route(
        '/documents/{type}/{uuid}',
        name: 'app_documents_deposer',
        methods: ['POST'],
        requirements: ['type' => 'projets|composantes|sous-composantes', 'uuid' => '[0-9a-fA-F-]{36}'],
    )]
    public function deposer(string $type, string $uuid, Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'documents.gerer');

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        $cible = $this->resoudreCible($type, $uuid);
        if (null === $cible) {
            return ProblemResponseFactory::creer(404, 'Entité introuvable');
        }

        $fichier = $request->files->get('fichier');
        if (null === $fichier) {
            return ProblemResponseFactory::creer(422, 'Fichier manquant', 'Le champ "fichier" (multipart) est requis.');
        }
        // Un envoi interrompu ou dépassant une limite PHP arrive ici en
        // fichier invalide, pas en erreur : sans cette garde, on stockerait
        // une ligne pointant vers un fichier tronqué.
        if (!$fichier->isValid()) {
            // Refus de PHP sur la taille (upload_max_filesize) : le dire tel
            // quel plutôt qu'un « fichier invalide » qui ne guide personne.
            if (\in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                return ProblemResponseFactory::creer(422, 'Fichier trop volumineux', 'La taille maximale est de 50 Mo.');
            }

            return ProblemResponseFactory::creer(422, 'Fichier invalide', "Le fichier n'a pas pu être reçu (dépassement d'une limite serveur ou envoi interrompu).");
        }
        $tailleOctets = (int) $fichier->getSize();
        if ($tailleOctets > self::TAILLE_MAX_OCTETS) {
            return ProblemResponseFactory::creer(422, 'Fichier trop volumineux', 'La taille maximale est de 50 Mo.');
        }

        $visibilite = VisibilitePieceJointe::tryFrom((string) $request->request->get('visibilite', 'tous'));
        if (null === $visibilite) {
            return ProblemResponseFactory::creer(422, 'Visibilité invalide');
        }
        // La visibilité par agents nommés suppose de choisir ces agents, ce
        // que seul l'écran des initiatives sait faire : ici, elle n'est pas
        // proposée plutôt qu'acceptée à vide, auquel cas personne — pas même
        // l'UGP — ne reverrait le document.
        if (VisibilitePieceJointe::AGENTS_SPECIFIQUES === $visibilite) {
            return ProblemResponseFactory::creer(422, 'Visibilité non applicable', 'La bibliothèque ne gère pas la visibilité par agents nommés.');
        }

        $nature = TypeDocumentRequete::lire($request);
        if ($nature instanceof JsonResponse) {
            return $nature;
        }

        $repertoire = $this->projectDir.'/var/uploads/documents';
        $this->filesystem->mkdir($repertoire);
        $nomOriginal = $fichier->getClientOriginalName();
        $nomStocke = Uuid::v4()->toRfc4122().'-'.$nomOriginal;
        $mime = $fichier->getClientMimeType() ?? 'application/octet-stream';
        $fichier->move($repertoire, $nomStocke);

        $document = new PieceJointe(
            self::TYPES[$type],
            (int) $cible->getId(),
            $nomOriginal,
            'var/uploads/documents/'.$nomStocke,
            $mime,
            $tailleOctets,
            $utilisateur,
        );
        $document->qualifier(...$nature);
        $document->definirVisibilite($visibilite);

        $libelle = $request->request->get('libelle');
        $document->setLibelle(\is_string($libelle) && '' !== trim($libelle) ? trim($libelle) : null);

        $this->em->persist($document);
        $this->em->flush();

        return new JsonResponse($this->normaliser($document), 201);
    }

    #[Route('/documents/{uuid}', name: 'app_document_retirer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function retirer(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'documents.gerer');

        $uuidObjet = $this->uuidOuNull($uuid);
        $document = null !== $uuidObjet ? $this->piecesJointes->findOneBy(['uuid' => $uuidObjet]) : null;
        if (null === $document) {
            return ProblemResponseFactory::creer(404, 'Document introuvable');
        }
        // Un justificatif d'étape ou de valeur d'indicateur n'est pas un
        // document de bibliothèque : il atteste d'une saisie, le retirer
        // viderait cette saisie de sa preuve.
        if (!\in_array($document->getEntiteType(), self::TYPES, true)) {
            return ProblemResponseFactory::creer(422, 'Document non retirable', 'Seuls les documents de la bibliothèque et des composantes peuvent être retirés ici.');
        }

        $chemin = $this->projectDir.'/'.$document->getChemin();
        $this->em->remove($document);
        $this->em->flush();
        // Après le flush : si la base refuse la suppression, le fichier est
        // toujours là et la ligne reste cohérente avec le disque.
        $this->filesystem->remove($chemin);

        return new Response(status: 204);
    }

    private function resoudreCible(string $type, string $uuid): Projet|Composante|SousComposante|null
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        if (null === $uuidObjet) {
            return null;
        }

        return match ($type) {
            'projets' => $this->projets->findOneBy(['uuid' => $uuidObjet]),
            'composantes' => $this->composantes->findOneBy(['uuid' => $uuidObjet]),
            'sous-composantes' => $this->sousComposantes->findOneBy(['uuid' => $uuidObjet]),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function normaliser(PieceJointe $document): array
    {
        return [
            'uuid' => $document->getUuid()->toRfc4122(),
            'libelle' => $document->getLibelle(),
            'nomFichier' => $document->getNomFichier(),
            'typeDocument' => $document->getTypeDocument()?->value,
            'typeDocumentLibelle' => $document->libelleTypeDocument(),
            'mime' => $document->getMime(),
            'tailleOctets' => $document->getTailleOctets(),
            'visibilite' => $document->getVisibilite()->value,
            // Déduite du MIME à la demande, jamais stockée (§ 5.1).
            'famille' => FamilleDocument::depuisMime($document->getMime())->value,
            'deposePar' => $document->getCreePar()->getNomComplet(),
            'creeLe' => $document->getCreeLe()->format(\DateTimeInterface::ATOM),
        ];
    }
}
