<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerLivrableRequest;
use App\Dto\RenseignerEtapeRequest;
use App\Entity\Activite;
use App\Entity\EtapeActivite;
use App\Entity\Livrable;
use App\Entity\PieceJointe;
use App\Entity\SousActivite;
use App\Entity\Utilisateur;
use App\Http\EtapeActiviteNormalizer;
use App\Http\ProblemResponseFactory;
use App\Http\TypeDocumentRequete;
use App\Repository\ActiviteRepository;
use App\Repository\EtapeActiviteRepository;
use App\Repository\GabaritEtapesRepository;
use App\Repository\LivrableRepository;
use App\Repository\SousActiviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Étapes de réalisation d'une activité (§ 6 du CR du 22/09/2026).
 *
 * Deux niveaux de droits, qui traduisent la décision de la réunion :
 * structurer relève de l'UGP, renseigner relève du terrain.
 *  - structurer (instancier un gabarit, ajouter ou retirer une étape,
 *    définir un livrable attendu) exige activites.creer, que seuls le
 *    coordonnateur et le responsable suivi-évaluation détiennent ;
 *  - renseigner (niveau atteint, valeur, observation, quantité réalisée)
 *    exige activites.modifier, portée-aware : le point focal le fait pour
 *    les activités de sa structure.
 */
#[Route('/api/v1')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class EtapeActiviteController
{
    use ApiControllerHelpersTrait;

    private const TAILLE_MAX_OCTETS = 10 * 1024 * 1024;

    public function __construct(
        private readonly ActiviteRepository $activites,
        private readonly SousActiviteRepository $sousActivites,
        private readonly EtapeActiviteRepository $etapes,
        private readonly GabaritEtapesRepository $gabarits,
        private readonly LivrableRepository $livrables,
        private readonly EtapeActiviteNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** Étapes propres de l'activité — celles de ses lignes se lisent sur chaque ligne. */
    #[Route('/activites/{uuid}/etapes', name: 'app_activite_etapes_liste', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function liste(string $uuid): JsonResponse
    {
        $activite = $this->trouverActivite($uuid);
        $this->exigerPermission($this->security, 'activites.consulter', $activite);

        return $this->listerEtapes($activite->getEtapesPropres());
    }

    /** Étapes d'une ligne du PTBA (sous-activité) — retours des RSE, octobre 2026. */
    #[Route('/sous-activites/{uuid}/etapes', name: 'app_sous_activite_etapes_liste', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function listeLigne(string $uuid): JsonResponse
    {
        $ligne = $this->trouverLigne($uuid);
        $this->exigerPermission($this->security, 'activites.consulter', $ligne);

        return $this->listerEtapes($ligne->getEtapesActives());
    }

    /**
     * Instancie un gabarit sur une activité : ses étapes sont copiées, pas
     * référencées (cf. GabaritEtapes). Refuse si l'activité a déjà des
     * étapes — réinstancier écraserait silencieusement un suivi en cours.
     */
    #[Route('/activites/{uuid}/etapes/instancier', name: 'app_activite_etapes_instancier', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function instancier(string $uuid, Request $request): Response
    {
        $activite = $this->trouverActivite($uuid);
        $this->exigerPermission($this->security, 'activites.creer', $activite);

        return $this->instancierSur($activite, null, $request);
    }

    #[Route('/sous-activites/{uuid}/etapes/instancier', name: 'app_sous_activite_etapes_instancier', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function instancierLigne(string $uuid, Request $request): Response
    {
        $ligne = $this->trouverLigne($uuid);
        $this->exigerPermission($this->security, 'activites.creer', $ligne);

        return $this->instancierSur($ligne->getActivite(), $ligne, $request);
    }

    private function instancierSur(Activite $activite, ?SousActivite $ligne, Request $request): Response
    {
        $existantes = null !== $ligne ? $ligne->getEtapesActives() : $activite->getEtapesPropres();
        if ($existantes->count() > 0) {
            return ProblemResponseFactory::creer(409, 'Étapes déjà définies', 'Des étapes existent déjà ici : retirez-les avant d\'appliquer un autre modèle.');
        }

        /** @var array<string, mixed> $corps */
        $corps = json_decode($request->getContent(), true) ?? [];
        $gabaritUuid = \is_string($corps['gabarit'] ?? null) ? $corps['gabarit'] : null;
        $gabarit = null !== $gabaritUuid ? $this->gabarits->findOneBy(['uuid' => $this->uuidOuNull($gabaritUuid)]) : null;
        if (null === $gabarit || $gabarit->estSupprime()) {
            return ProblemResponseFactory::creer(422, 'Modèle d\'étapes introuvable');
        }

        foreach ($gabarit->getEtapes() as $modele) {
            $this->em->persist(new EtapeActivite($activite, $modele->getLibelle(), $modele->getOrdre(), $ligne));
        }

        $this->em->flush();

        return $this->listerEtapes(null !== $ligne ? $ligne->getEtapesActives() : $activite->getEtapesPropres(), 201);
    }

    /** Ajoute une étape hors gabarit — une activité atypique n'a pas à adopter un modèle entier pour une seule étape de plus. */
    #[Route('/activites/{uuid}/etapes', name: 'app_activite_etape_ajouter', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function ajouter(string $uuid, Request $request): Response
    {
        $activite = $this->trouverActivite($uuid);
        $this->exigerPermission($this->security, 'activites.creer', $activite);

        return $this->ajouterSur($activite, null, $request);
    }

    #[Route('/sous-activites/{uuid}/etapes', name: 'app_sous_activite_etape_ajouter', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function ajouterLigne(string $uuid, Request $request): Response
    {
        $ligne = $this->trouverLigne($uuid);
        $this->exigerPermission($this->security, 'activites.creer', $ligne);

        return $this->ajouterSur($ligne->getActivite(), $ligne, $request);
    }

    private function ajouterSur(Activite $activite, ?SousActivite $ligne, Request $request): Response
    {
        /** @var array<string, mixed> $corps */
        $corps = json_decode($request->getContent(), true) ?? [];
        $libelle = \is_string($corps['libelle'] ?? null) ? trim($corps['libelle']) : '';
        if ('' === $libelle) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'Le libellé de l\'étape est requis.');
        }

        $ordre = 0;
        foreach (null !== $ligne ? $ligne->getEtapesActives() : $activite->getEtapesPropres() as $existante) {
            $ordre = max($ordre, $existante->getOrdre());
        }

        $etape = new EtapeActivite($activite, $libelle, $ordre + 1, $ligne);
        if (\is_string($corps['echeance'] ?? null) && '' !== $corps['echeance']) {
            $etape->setEcheance(new \DateTimeImmutable($corps['echeance']));
        }
        if (\is_string($corps['unite'] ?? null) && '' !== $corps['unite']) {
            $etape->setUnite($corps['unite']);
        }

        $this->em->persist($etape);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($etape), 201);
    }

    /** Renseigne le niveau atteint, la valeur, l'observation et la date — les quatre champs du § 6, en un seul geste. */
    #[Route('/etapes/{uuid}', name: 'app_etape_renseigner', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function renseigner(string $uuid, Request $request): Response
    {
        $etape = $this->trouverEtape($uuid);
        $this->exigerPermission($this->security, 'activites.modifier', $etape);

        /** @var RenseignerEtapeRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), RenseignerEtapeRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $etape->renseigner(
            $donnees->avancement,
            $donnees->valeurActuelle,
            $donnees->observation,
            new \DateTimeImmutable($donnees->dateMiseAJour),
        );
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($etape));
    }

    /** Suppression logique : une étape retirée a pu porter un suivi et des justificatifs. */
    #[Route('/etapes/{uuid}', name: 'app_etape_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimer(string $uuid): Response
    {
        $etape = $this->trouverEtape($uuid);
        $this->exigerPermission($this->security, 'activites.creer', $etape);

        $etape->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /** Définir ce qui est attendu relève de l'UGP (activites.creer) ; constater ce qui est réalisé relève du terrain (cf. modifierLivrable). */
    #[Route('/etapes/{uuid}/livrables', name: 'app_etape_livrable_creer', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function creerLivrable(string $uuid, Request $request): Response
    {
        $etape = $this->trouverEtape($uuid);
        $this->exigerPermission($this->security, 'activites.creer', $etape);

        /** @var CreerLivrableRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerLivrableRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $livrable = new Livrable($etape, $donnees->libelle, $donnees->quantiteAttendue);
        $livrable->setUnite($donnees->unite);
        $this->em->persist($livrable);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($etape), 201);
    }

    /**
     * La quantité réalisée se constate avec activites.modifier (le terrain),
     * tandis que le libellé et la quantité attendue — ce qui a été commandé —
     * restent du ressort de l'UGP.
     */
    #[Route('/livrables/{uuid}', name: 'app_livrable_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifierLivrable(string $uuid, Request $request): Response
    {
        $livrable = $this->trouverLivrable($uuid);
        $etape = $livrable->getEtape();
        $this->exigerPermission($this->security, 'activites.modifier', $etape);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('quantiteRealisee', $donnees)) {
            if (!is_numeric($donnees['quantiteRealisee'])) {
                return ProblemResponseFactory::creer(422, 'Données invalides', 'quantiteRealisee doit être un nombre.');
            }
            $livrable->setQuantiteRealisee((string) $donnees['quantiteRealisee']);
        }

        $redefinit = \array_key_exists('libelle', $donnees) || \array_key_exists('quantiteAttendue', $donnees) || \array_key_exists('unite', $donnees);
        if ($redefinit) {
            if (!$this->security->isGranted('activites.creer', $etape)) {
                throw new AccessDeniedException();
            }
            if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle']) && '' !== $donnees['libelle']) {
                $livrable->setLibelle($donnees['libelle']);
            }
            if (\array_key_exists('unite', $donnees)) {
                $livrable->setUnite(\is_string($donnees['unite']) ? $donnees['unite'] : null);
            }
            if (\array_key_exists('quantiteAttendue', $donnees)) {
                if (!is_numeric($donnees['quantiteAttendue'])) {
                    return ProblemResponseFactory::creer(422, 'Données invalides', 'quantiteAttendue doit être un nombre.');
                }
                $livrable->setQuantiteAttendue((string) $donnees['quantiteAttendue']);
            }
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($etape));
    }

    #[Route('/livrables/{uuid}', name: 'app_livrable_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimerLivrable(string $uuid): Response
    {
        $livrable = $this->trouverLivrable($uuid);
        $this->exigerPermission($this->security, 'activites.creer', $livrable->getEtape());

        $livrable->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /**
     * Pièce justificative attachée à une étape (§ 6 du CR du
     * 22/09/2026). Joindre un justificatif fait partie du renseignement, pas
     * de la structuration : activites.modifier suffit donc, comme pour le
     * niveau atteint. Même stockage et même limite que les autres pièces
     * jointes de l'application (10 Mo, disque local).
     */
    #[Route('/etapes/{uuid}/pieces-jointes', name: 'app_etape_piece_jointe_creer', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function creerPieceJointe(string $uuid, Request $request): Response
    {
        $etape = $this->trouverEtape($uuid);
        $this->exigerPermission($this->security, 'activites.modifier', $etape);

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        $fichier = $request->files->get('fichier');
        if (null === $fichier) {
            return ProblemResponseFactory::creer(422, 'Fichier manquant', 'Le champ "fichier" (multipart) est requis.');
        }
        if (!$fichier->isValid()) {
            return ProblemResponseFactory::creer(422, 'Fichier invalide', "Le fichier n'a pas pu être reçu (dépassement d'une limite serveur ou envoi interrompu).");
        }
        $tailleOctets = (int) $fichier->getSize();
        if ($tailleOctets > self::TAILLE_MAX_OCTETS) {
            return ProblemResponseFactory::creer(422, 'Fichier trop volumineux', 'La taille maximale est de 10 Mo.');
        }

        $nature = TypeDocumentRequete::lire($request);
        if ($nature instanceof JsonResponse) {
            return $nature;
        }

        $repertoire = $this->projectDir.'/var/uploads/etapes';
        $this->filesystem->mkdir($repertoire);
        $nomStocke = Uuid::v4()->toRfc4122().'-'.$fichier->getClientOriginalName();
        $nomOriginal = $fichier->getClientOriginalName();
        $mime = $fichier->getClientMimeType() ?? 'application/octet-stream';
        $fichier->move($repertoire, $nomStocke);

        $pieceJointe = new PieceJointe(
            'etape_activite',
            (int) $etape->getId(),
            $nomOriginal,
            'var/uploads/etapes/'.$nomStocke,
            $mime,
            $tailleOctets,
            $utilisateur,
        );
        $pieceJointe->qualifier(...$nature);
        $this->em->persist($pieceJointe);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($etape), 201);
    }

    private function trouverActivite(string $uuid): Activite
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $activite = null !== $uuidObjet ? $this->activites->findOneBy(['uuid' => $uuidObjet]) : null;

        if (null === $activite || $activite->estSupprime()) {
            throw new NotFoundHttpException('Activité introuvable.');
        }

        return $activite;
    }

    /** @param iterable<EtapeActivite> $etapes */
    private function listerEtapes(iterable $etapes, int $statut = 200): JsonResponse
    {
        $liste = [];
        foreach ($etapes as $etape) {
            $liste[] = $etape;
        }
        usort($liste, static fn (EtapeActivite $a, EtapeActivite $b) => $a->getOrdre() <=> $b->getOrdre());

        return new JsonResponse(['donnees' => array_map($this->normalizer->normaliser(...), $liste)], $statut);
    }

    private function trouverLigne(string $uuid): SousActivite
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $ligne = null !== $uuidObjet ? $this->sousActivites->findOneBy(['uuid' => $uuidObjet]) : null;

        if (null === $ligne || $ligne->estSupprime()) {
            throw new NotFoundHttpException('Sous-activité introuvable.');
        }

        return $ligne;
    }

    private function trouverEtape(string $uuid): EtapeActivite
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $etape = null !== $uuidObjet ? $this->etapes->findOneBy(['uuid' => $uuidObjet]) : null;

        if (null === $etape || $etape->estSupprime()) {
            throw new NotFoundHttpException('Étape introuvable.');
        }

        return $etape;
    }

    private function trouverLivrable(string $uuid): Livrable
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $livrable = null !== $uuidObjet ? $this->livrables->findOneBy(['uuid' => $uuidObjet]) : null;

        if (null === $livrable || $livrable->estSupprime()) {
            throw new NotFoundHttpException('Livrable introuvable.');
        }

        return $livrable;
    }
}
