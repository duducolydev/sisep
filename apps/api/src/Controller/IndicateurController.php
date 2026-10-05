<?php

declare(strict_types=1);

namespace App\Controller;

use App\Courriel\ServiceCourriel;
use App\Dto\CreerIndicateurRequest;
use App\Dto\SaisirValeurIndicateurRequest;
use App\Entity\Indicateur;
use App\Entity\IndicateurDesagregation;
use App\Entity\IndicateurValeur;
use App\Entity\InvitationIndicateur;
use App\Entity\PieceJointe;
use App\Entity\Utilisateur;
use App\Enum\FrequenceIndicateur;
use App\Enum\SensIndicateur;
use App\Enum\TypeIndicateur;
use App\Http\IndicateurNormalizer;
use App\Http\IndicateurValeurNormalizer;
use App\Http\ProblemResponseFactory;
use App\Repository\ComposanteRepository;
use App\Repository\IndicateurRepository;
use App\Repository\IndicateurValeurRepository;
use App\Repository\InvitationIndicateurRepository;
use App\Repository\ProjetRepository;
use App\Repository\StructureRepository;
use App\Repository\UtilisateurRepository;
use App\Security\IndicateurAccesResolver;
use App\Security\PermissionsResolver;
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
 * Le module Indicateurs de performance est réservé à l'équipe PHASAOC —
 * les sectoriels (rôle point_focal_structure) ne le voient ni n'y
 * accèdent par défaut, y compris pour la seule lecture :
 * indicateurs.consulter (portée globale) garde donc liste/détail/valeurs,
 * en plus de definir/valider déjà en portée globale (référentiel
 * méthodologique et validation de contrôle interne). saisir reste
 * portée-aware (cf. saisirValeur ci-dessous) mais n'est plus accordée à
 * point_focal_structure (§ décision : la saisie devient centralisée à
 * l'équipe PHASAOC).
 *
 * Exception au cas par cas : le coordonnateur ou le responsable
 * suivi-évaluation (indicateurs.inviter) peuvent inviter un sectoriel sur
 * un indicateur précis (InvitationIndicateur) — cela lui ouvre l'accès à
 * ce seul indicateur (consultation + saisie) sans lui redonner les
 * permissions globales. Voir exigerSaisieAutorisee() et les branches
 * d'accès par invitation dans liste()/detail()/listerValeurs().
 */
#[Route('/api/v1/indicateurs')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class IndicateurController
{
    use ApiControllerHelpersTrait;

    private const TAILLE_MAX_OCTETS = 10 * 1024 * 1024;

    public function __construct(
        private readonly IndicateurRepository $indicateurs,
        private readonly IndicateurValeurRepository $valeurs,
        private readonly InvitationIndicateurRepository $invitations,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly IndicateurAccesResolver $accesResolver,
        private readonly ServiceCourriel $courriels,
        private readonly ProjetRepository $projets,
        private readonly ComposanteRepository $composantes,
        private readonly StructureRepository $structures,
        private readonly IndicateurNormalizer $normalizer,
        private readonly IndicateurValeurNormalizer $valeurNormalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'app_indicateur_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        $projet = null;
        $projetUuid = $request->query->get('projet');
        if (\is_string($projetUuid)) {
            $projet = $this->projets->findOneBy(['uuid' => $this->uuidOuNull($projetUuid)]);
        }

        $type = null;
        $typeBrut = $request->query->get('type');
        if (\is_string($typeBrut)) {
            $type = TypeIndicateur::tryFrom($typeBrut);
            if (null === $type) {
                return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'type doit valoir IOD ou IRI.');
            }
        }

        $composante = null;
        $composanteUuid = $request->query->get('composante');
        if (\is_string($composanteUuid)) {
            $composante = $this->composantes->findOneBy(['uuid' => $this->uuidOuNull($composanteUuid)]);
        }

        $structureResponsable = null;
        $structureUuid = $request->query->get('structureResponsable');
        if (\is_string($structureUuid)) {
            $structureResponsable = $this->structures->findOneBy(['uuid' => $this->uuidOuNull($structureUuid)]);
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        if ($this->permissionsResolver->pour($utilisateur)->estGlobale('indicateurs.consulter')) {
            $indicateurs = $this->indicateurs->filtrer($projet, $type, $composante, $structureResponsable);
        } elseif ([] !== ($invites = $this->invitations->indicateursInvites($utilisateur))) {
            $indicateurs = array_values(array_filter(
                $invites,
                static fn (Indicateur $i) => (null === $projet || $i->getProjet() === $projet)
                    && (null === $type || $i->getType() === $type)
                    && (null === $composante || $i->getComposante() === $composante)
                    && (null === $structureResponsable || $i->getStructureResponsable() === $structureResponsable),
            ));
        } else {
            throw new AccessDeniedException();
        }

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $indicateurs),
        ]);
    }

    #[Route('/{uuid}', name: 'app_indicateur_detail', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function detail(string $uuid): JsonResponse
    {
        $indicateur = $this->trouver($uuid);
        $this->exigerConsultationAutorisee($indicateur);

        return new JsonResponse($this->normalizer->normaliser($indicateur));
    }

    #[Route('', name: 'app_indicateur_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'indicateurs.definir');

        /** @var CreerIndicateurRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerIndicateurRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $projetUuid = $this->uuidOuNull($request->query->get('projet') ?? '');
        $projet = null !== $projetUuid ? $this->projets->findOneBy(['uuid' => $projetUuid]) : $this->projets->findOneBy([]);
        if (null === $projet) {
            return ProblemResponseFactory::creer(422, 'Projet introuvable');
        }

        $indicateur = new Indicateur(
            $projet,
            $donnees->code,
            $donnees->libelle,
            TypeIndicateur::from($donnees->type),
            $donnees->unite,
            SensIndicateur::from($donnees->sens),
            $donnees->valeurReference,
            new \DateTimeImmutable($donnees->dateReference),
            $donnees->valeurCible,
            new \DateTimeImmutable($donnees->dateCible),
            FrequenceIndicateur::from($donnees->frequence),
            $donnees->source,
        );
        $indicateur->setPrecisionLibelle($donnees->precisionLibelle);
        $indicateur->setValeurIntermediaire($donnees->valeurIntermediaire);
        if (null !== $donnees->dateIntermediaire) {
            $indicateur->setDateIntermediaire(new \DateTimeImmutable($donnees->dateIntermediaire));
        }

        if (null !== $donnees->composante) {
            $composante = $this->composantes->findOneBy(['uuid' => $this->uuidOuNull($donnees->composante)]);
            $indicateur->setComposante($composante);
        }
        if (null !== $donnees->structureResponsable) {
            $structure = $this->structures->findOneBy(['uuid' => $this->uuidOuNull($donnees->structureResponsable)]);
            $indicateur->setStructureResponsable($structure);
        }

        $this->em->persist($indicateur);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($indicateur), 201);
    }

    #[Route('/{uuid}', name: 'app_indicateur_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $indicateur = $this->trouver($uuid);
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'indicateurs.definir');

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle'])) {
            $indicateur->setLibelle($donnees['libelle']);
        }
        if (\array_key_exists('precisionLibelle', $donnees)) {
            $indicateur->setPrecisionLibelle(\is_string($donnees['precisionLibelle']) ? $donnees['precisionLibelle'] : null);
        }
        if (\array_key_exists('unite', $donnees) && \is_string($donnees['unite'])) {
            $indicateur->setUnite($donnees['unite']);
        }
        if (\array_key_exists('valeurIntermediaire', $donnees)) {
            $indicateur->setValeurIntermediaire(\is_string($donnees['valeurIntermediaire']) ? $donnees['valeurIntermediaire'] : null);
        }
        if (\array_key_exists('dateIntermediaire', $donnees)) {
            $indicateur->setDateIntermediaire(\is_string($donnees['dateIntermediaire']) ? new \DateTimeImmutable($donnees['dateIntermediaire']) : null);
        }
        if (\array_key_exists('valeurCible', $donnees) && \is_string($donnees['valeurCible'])) {
            $indicateur->setValeurCible($donnees['valeurCible']);
        }
        if (\array_key_exists('dateCible', $donnees) && \is_string($donnees['dateCible'])) {
            $indicateur->setDateCible(new \DateTimeImmutable($donnees['dateCible']));
        }
        if (\array_key_exists('source', $donnees) && \is_string($donnees['source'])) {
            $indicateur->setSource($donnees['source']);
        }
        if (\array_key_exists('actif', $donnees) && \is_bool($donnees['actif'])) {
            $indicateur->setActif($donnees['actif']);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($indicateur));
    }

    #[Route('/{uuid}/valeurs', name: 'app_indicateur_valeurs_liste', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function listerValeurs(string $uuid): JsonResponse
    {
        $indicateur = $this->trouver($uuid);
        $this->exigerConsultationAutorisee($indicateur);

        return new JsonResponse([
            'donnees' => array_map($this->valeurNormalizer->normaliser(...), $this->valeurs->lister($indicateur)),
        ]);
    }

    /**
     * indicateurs.saisir (§ 7.2) est portée-aware, contrairement à
     * definir/valider : une structure responsable de l'indicateur (§ 4.4)
     * peut y saisir sa valeur périodique sans détenir la permission
     * globalement — cf. Indicateur::getStructurePourPortee(). Un sectoriel
     * invité sur cet indicateur précis (App\Entity\InvitationIndicateur) y
     * a également accès sans détenir la permission du tout — cf.
     * exigerSaisieAutorisee().
     *
     * Accepte soit une valeur absolue ("valeur"), soit un ajustement
     * ("delta", +/- depuis la dernière saisie) — § décision : la base d'un
     * delta est la dernière saisie sur la période visée si elle existe déjà
     * (upsert), sinon la plus récente avant cette période (validée ou non),
     * sinon la valeur de référence de l'indicateur. Arithmétique en bcmath
     * pour éviter toute perte de précision décimale sur une valeur stockée
     * et cumulative (contrairement à TauxAtteinteCalculator, qui caste en
     * float pour un ratio dérivé non stocké).
     *
     * Une resaisie sur une période déjà renseignée n'échoue plus (409) :
     * elle met à jour la ligne existante via ajusterValeur(), qui remet le
     * statut à BROUILLON (§ séparation saisie/validation).
     */
    #[Route('/{uuid}/valeurs', name: 'app_indicateur_valeurs_saisir', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function saisirValeur(string $uuid, Request $request): Response
    {
        $indicateur = $this->trouver($uuid);
        $this->exigerSaisieAutorisee($indicateur);

        /** @var SaisirValeurIndicateurRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), SaisirValeurIndicateurRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $valeurFournie = null !== $donnees->valeur && '' !== $donnees->valeur;
        $deltaFournie = null !== $donnees->delta && '' !== $donnees->delta;
        if ($valeurFournie === $deltaFournie) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'Fournissez exactement l\'une des deux valeurs : "valeur" ou "delta".');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        $desagregations = $this->extraireDesagregations($request);
        if ($desagregations instanceof JsonResponse) {
            return $desagregations;
        }

        $periode = new \DateTimeImmutable($donnees->periode);
        $existante = $this->valeurs->trouverPourPeriode($indicateur, $periode);

        if ($deltaFournie) {
            $base = $existante ?? $this->valeurs->derniere($indicateur, $periode);
            $valeurBase = $base?->getValeur() ?? $indicateur->getValeurReference();
            $valeurCalculee = bcadd($valeurBase, $donnees->delta, 4);
        } else {
            $valeurCalculee = $donnees->valeur;
        }

        if (null !== $existante) {
            $existante->ajusterValeur($valeurCalculee);
            $existante->setCommentaire($donnees->commentaire);
            $valeur = $existante;
        } else {
            $valeur = new IndicateurValeur($indicateur, $periode, $valeurCalculee, $utilisateur);
            $valeur->setCommentaire($donnees->commentaire);
            $this->em->persist($valeur);
        }

        foreach ($desagregations as [$dimension, $modalite, $valeurDesagregation]) {
            $this->em->persist(new IndicateurDesagregation($valeur, $dimension, $modalite, $valeurDesagregation));
        }

        $this->em->flush();

        return new JsonResponse($this->valeurNormalizer->normaliser($valeur), null === $existante ? 201 : 200);
    }

    #[Route('/valeurs/{uuid}/valider', name: 'app_indicateur_valeur_valider', methods: ['POST'])]
    public function validerValeur(string $uuid): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'indicateurs.valider');

        $uuidObjet = $this->uuidOuNull($uuid);
        $valeur = null !== $uuidObjet ? $this->valeurs->findOneBy(['uuid' => $uuidObjet]) : null;
        if (null === $valeur) {
            throw new NotFoundHttpException("Valeur d'indicateur introuvable.");
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return new JsonResponse(['title' => 'Authentification requise', 'status' => 401], 401);
        }

        $valeur->valider($utilisateur);
        $this->em->flush();

        return new JsonResponse($this->valeurNormalizer->normaliser($valeur));
    }

    /** Pièce jointe justificative sur une valeur — même stockage/limite que InitiativeController::creerPieceJointe()/RapportController::transmettre() (10 Mo, disque local). Attache-après-coup, appelée juste après saisirValeur(). */
    #[Route('/valeurs/{uuid}/pieces-jointes', name: 'app_indicateur_valeur_piece_jointe_creer', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function creerPieceJointe(string $uuid, Request $request): Response
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $valeur = null !== $uuidObjet ? $this->valeurs->findOneBy(['uuid' => $uuidObjet]) : null;
        if (null === $valeur) {
            throw new NotFoundHttpException("Valeur d'indicateur introuvable.");
        }

        $this->exigerSaisieAutorisee($valeur->getIndicateur());

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        $fichier = $request->files->get('fichier');
        if (null === $fichier) {
            return ProblemResponseFactory::creer(422, 'Fichier manquant', 'Le champ "fichier" (multipart) est requis.');
        }
        if (!$fichier->isValid()) {
            return ProblemResponseFactory::creer(422, 'Fichier invalide', 'Le fichier n\'a pas pu être reçu (dépassement d\'une limite serveur ou envoi interrompu).');
        }
        $tailleOctets = (int) $fichier->getSize();
        if ($tailleOctets > self::TAILLE_MAX_OCTETS) {
            return ProblemResponseFactory::creer(422, 'Fichier trop volumineux', 'La taille maximale est de 10 Mo.');
        }

        $repertoire = $this->projectDir.'/var/uploads/indicateurs';
        $this->filesystem->mkdir($repertoire);
        $nomStocke = Uuid::v4()->toRfc4122().'-'.$fichier->getClientOriginalName();
        $nomOriginal = $fichier->getClientOriginalName();
        $mime = $fichier->getClientMimeType() ?? 'application/octet-stream';
        $fichier->move($repertoire, $nomStocke);

        $pieceJointe = new PieceJointe(
            'indicateur_valeur',
            (int) $valeur->getId(),
            $nomOriginal,
            'var/uploads/indicateurs/'.$nomStocke,
            $mime,
            $tailleOctets,
            $utilisateur,
        );
        $this->em->persist($pieceJointe);
        $this->em->flush();

        return new JsonResponse($this->valeurNormalizer->normaliser($valeur), 201);
    }

    /** Sectoriels éligibles à une invitation (§ décision) — mirrors InitiativeController::responsablesEligibles(), filtré aux porteurs du rôle point_focal_structure. */
    #[Route('/sectoriels-eligibles', name: 'app_indicateur_sectoriels_eligibles', methods: ['GET'])]
    public function sectorielsEligibles(): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'indicateurs.inviter');

        return new JsonResponse([
            'donnees' => array_map(static fn (Utilisateur $u) => [
                'uuid' => $u->getUuid()->toRfc4122(),
                'nomComplet' => $u->getNomComplet(),
            ], $this->utilisateurs->listerParRole('point_focal_structure')),
        ]);
    }

    #[Route('/{uuid}/invitations', name: 'app_indicateur_invitations_liste', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function listerInvitations(string $uuid): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'indicateurs.inviter');
        $indicateur = $this->trouver($uuid);

        return new JsonResponse([
            'donnees' => array_map(static fn (InvitationIndicateur $i) => [
                'uuid' => $i->getUuid()->toRfc4122(),
                'utilisateur' => ['uuid' => $i->getUtilisateur()->getUuid()->toRfc4122(), 'nomComplet' => $i->getUtilisateur()->getNomComplet()],
                'invitePar' => $i->getInvitePar()->getNomComplet(),
                'inviteLe' => $i->getInviteLe()->format(\DateTimeInterface::ATOM),
            ], $this->invitations->lister($indicateur)),
        ]);
    }

    #[Route('/{uuid}/invitations', name: 'app_indicateur_invitation_creer', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function inviter(string $uuid, Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'indicateurs.inviter');
        $indicateur = $this->trouver($uuid);

        $utilisateurCourant = $this->security->getUser();
        if (!$utilisateurCourant instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        /** @var array<string, mixed> $corps */
        $corps = json_decode($request->getContent(), true) ?? [];
        $utilisateurUuid = \is_string($corps['utilisateurUuid'] ?? null) ? $corps['utilisateurUuid'] : null;
        $invite = null !== $utilisateurUuid ? $this->utilisateurs->findOneBy(['uuid' => $this->uuidOuNull($utilisateurUuid)]) : null;
        if (null === $invite) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'utilisateurUuid est requis et doit correspondre à un compte existant.');
        }

        if ($this->invitations->estInvite($indicateur, $invite)) {
            return ProblemResponseFactory::creer(409, 'Déjà invité', 'Ce compte a déjà accès à cet indicateur.');
        }

        $invitation = new InvitationIndicateur($indicateur, $invite, $utilisateurCourant);
        $this->em->persist($invitation);
        $this->em->flush();

        $courrielEnvoye = $this->courriels->invitationIndicateur($invitation);

        return new JsonResponse([
            'uuid' => $invitation->getUuid()->toRfc4122(),
            'utilisateur' => ['uuid' => $invite->getUuid()->toRfc4122(), 'nomComplet' => $invite->getNomComplet()],
            'invitePar' => $utilisateurCourant->getNomComplet(),
            'inviteLe' => $invitation->getInviteLe()->format(\DateTimeInterface::ATOM),
            'courrielEnvoye' => $courrielEnvoye,
        ], 201);
    }

    /** Révocation = suppression physique, comme UtilisateurRole (§ décision : octroi de type ACL, pas d'historique budgétaire). */
    #[Route('/invitations/{uuid}', name: 'app_indicateur_invitation_revoquer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function revoquerInvitation(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'indicateurs.inviter');

        $uuidObjet = $this->uuidOuNull($uuid);
        $invitation = null !== $uuidObjet ? $this->invitations->findOneBy(['uuid' => $uuidObjet]) : null;
        if (null === $invitation) {
            throw new NotFoundHttpException('Invitation introuvable.');
        }

        $this->em->remove($invitation);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /**
     * @return JsonResponse|list<array{0: string, 1: string, 2: string}>
     */
    private function extraireDesagregations(Request $request): JsonResponse|array
    {
        /** @var array<string, mixed> $corps */
        $corps = json_decode($request->getContent(), true) ?? [];
        $brutes = $corps['desagregations'] ?? [];
        if (!\is_array($brutes)) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'desagregations doit être un tableau.');
        }

        $desagregations = [];
        foreach ($brutes as $entree) {
            if (
                !\is_array($entree)
                || !\is_string($entree['dimension'] ?? null) || '' === $entree['dimension']
                || !\is_string($entree['modalite'] ?? null) || '' === $entree['modalite']
                || !\is_string($entree['valeur'] ?? null) || '' === $entree['valeur']
            ) {
                return ProblemResponseFactory::creer(
                    422,
                    'Données invalides',
                    'Chaque désagrégation doit fournir dimension, modalite et valeur.',
                );
            }
            $desagregations[] = [$entree['dimension'], $entree['modalite'], $entree['valeur']];
        }

        return $desagregations;
    }

    /**
     * indicateurs.saisir (portée-aware) OU une invitation active sur cet
     * indicateur précis (App\Entity\InvitationIndicateur) — même
     * composition que InitiativeController::exigerResponsableOuGerer().
     */
    private function exigerSaisieAutorisee(Indicateur $indicateur): void
    {
        if (!$this->accesResolver->peutSaisir($indicateur)) {
            throw new AccessDeniedException();
        }
    }

    /** indicateurs.consulter (portée globale) OU une invitation active sur cet indicateur précis. */
    private function exigerConsultationAutorisee(Indicateur $indicateur): void
    {
        if (!$this->accesResolver->peutConsulter($indicateur)) {
            throw new AccessDeniedException();
        }
    }

    private function trouver(string $uuid): Indicateur
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $indicateur = null !== $uuidObjet ? $this->indicateurs->findOneBy(['uuid' => $uuidObjet]) : null;

        return $indicateur ?? throw new NotFoundHttpException('Indicateur introuvable.');
    }
}
