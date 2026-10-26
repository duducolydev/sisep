<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerInitiativeActiviteRequest;
use App\Dto\CreerInitiativeRequest;
use App\Dto\CreerNoteInitiativeRequest;
use App\Dto\CreerTacheInitiativeRequest;
use App\Entity\Initiative;
use App\Entity\InitiativeActivite;
use App\Entity\NoteInitiative;
use App\Entity\PieceJointe;
use App\Entity\TacheInitiative;
use App\Entity\Utilisateur;
use App\Enum\VisibilitePieceJointe;
use App\Http\InitiativeNormalizer;
use App\Http\ProblemResponseFactory;
use App\Http\TypeDocumentRequete;
use App\Repository\ComposanteRepository;
use App\Repository\InitiativeActiviteRepository;
use App\Repository\InitiativeRepository;
use App\Repository\SousComposanteRepository;
use App\Repository\TacheInitiativeRepository;
use App\Repository\UtilisateurRepository;
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
 * Suivi des projets (« Projets » à l'écran, Initiative dans le code — cf.
 * Initiative::class). initiatives.gerer (portée globale, coordonnateur et
 * responsable suivi-évaluation) crée/structure les projets et leurs
 * activités et réserve la clôture d'une activité. Seule exception : le(s)
 * responsable(s) d'une InitiativeActivite peuvent gérer ses tâches sans
 * détenir la permission — la portée ici est par personne, pas par
 * structure, donc hors du mécanisme PermissionVoter/PorteeParStructure.
 */
#[Route('/api/v1')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class InitiativeController
{
    use ApiControllerHelpersTrait;

    private const CONSULTATION = 'initiatives.consulter';

    private const TAILLE_MAX_OCTETS = 10 * 1024 * 1024;

    public function __construct(
        private readonly InitiativeRepository $initiatives,
        private readonly InitiativeActiviteRepository $activites,
        private readonly TacheInitiativeRepository $taches,
        private readonly ComposanteRepository $composantes,
        private readonly SousComposanteRepository $sousComposantes,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly InitiativeNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('/initiatives', name: 'app_initiative_liste', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        $this->exigerConsultation();

        return new JsonResponse([
            'donnees' => array_map($this->normalizer->normaliser(...), $this->initiatives->listerNonSupprimees()),
        ]);
    }

    /**
     * Liste légère (uuid + nom) pour peupler un sélecteur de collègues —
     * volontairement distincte de /admin/utilisateurs (admin.utilisateurs,
     * coordonnateur seul). Sert à la fois au choix des responsables d'une
     * activité et à celui des agents visés par la visibilité « Agents
     * spécifiques » d'un document. Le module étant réservé à l'UGP, seuls
     * les comptes qui y ont accès sont proposés : un responsable qui ne
     * verrait pas le projet ne pourrait pas s'en occuper.
     */
    #[Route('/initiatives/responsables-eligibles', name: 'app_initiative_responsables_eligibles', methods: ['GET'])]
    public function responsablesEligibles(): JsonResponse
    {
        $this->exigerConsultation();

        $eligibles = array_filter(
            $this->utilisateurs->listerNonSupprimes(),
            fn (Utilisateur $u) => $u->estActif() && $this->permissionsResolver->pour($u)->estGlobale(self::CONSULTATION),
        );

        return new JsonResponse([
            'donnees' => array_values(array_map(static fn (Utilisateur $u) => [
                'uuid' => $u->getUuid()->toRfc4122(),
                'nomComplet' => $u->getNomComplet(),
            ], $eligibles)),
        ]);
    }

    #[Route('/initiatives/{uuid}', name: 'app_initiative_detail', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function detail(string $uuid): JsonResponse
    {
        $this->exigerConsultation();

        return new JsonResponse($this->normalizer->normaliser($this->trouverInitiative($uuid)));
    }

    #[Route('/initiatives', name: 'app_initiative_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'initiatives.gerer');

        /** @var CreerInitiativeRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerInitiativeRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        if (null !== $donnees->composante && null !== $donnees->sousComposante) {
            return ProblemResponseFactory::creer(422, 'Lien PTBA invalide', 'Un projet ne peut être lié qu\'à une composante OU une sous-composante, pas les deux.');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return ProblemResponseFactory::creer(401, 'Authentification requise');
        }

        $initiative = new Initiative($donnees->libelle, $utilisateur);
        $initiative->setDescription($donnees->description);

        $composante = null !== $donnees->composante ? $this->composantes->findOneBy(['uuid' => $this->uuidOuNull($donnees->composante)]) : null;
        $sousComposante = null !== $donnees->sousComposante ? $this->sousComposantes->findOneBy(['uuid' => $this->uuidOuNull($donnees->sousComposante)]) : null;
        $initiative->definirLienPtba($composante, $sousComposante);

        $this->em->persist($initiative);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($initiative), 201);
    }

    #[Route('/initiatives/{uuid}', name: 'app_initiative_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'initiatives.gerer');
        $initiative = $this->trouverInitiative($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle']) && '' !== $donnees['libelle']) {
            $initiative->setLibelle($donnees['libelle']);
        }
        if (\array_key_exists('description', $donnees)) {
            $initiative->setDescription(\is_string($donnees['description']) ? $donnees['description'] : null);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($initiative));
    }

    #[Route('/initiatives/{uuid}', name: 'app_initiative_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimer(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'initiatives.gerer');

        $initiative = $this->trouverInitiative($uuid);
        $initiative->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    #[Route('/initiative-activites', name: 'app_initiative_activite_creer', methods: ['POST'])]
    public function creerActivite(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'initiatives.gerer');

        /** @var CreerInitiativeActiviteRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerInitiativeActiviteRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $initiative = $this->initiatives->findOneBy(['uuid' => $this->uuidOuNull($donnees->initiative)]);
        if (null === $initiative) {
            return ProblemResponseFactory::creer(422, 'Projet introuvable');
        }

        $ordre = $donnees->ordre ?? (\count($this->activites->listerNonSupprimees($initiative)) + 1);
        $activite = new InitiativeActivite($initiative, $donnees->libelle, $ordre);
        if (null !== $donnees->dateDebut) {
            $activite->setDateDebut(new \DateTimeImmutable($donnees->dateDebut));
        }
        if (null !== $donnees->echeance) {
            $activite->setEcheance(new \DateTimeImmutable($donnees->echeance));
        }

        foreach ($donnees->responsables as $responsableUuid) {
            $responsable = $this->utilisateurs->findOneBy(['uuid' => $this->uuidOuNull($responsableUuid)]);
            if (null === $responsable) {
                return ProblemResponseFactory::creer(422, 'Compte utilisateur introuvable pour un responsable');
            }
            $activite->ajouterResponsable($responsable);
        }

        $this->em->persist($activite);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($initiative), 201);
    }

    /**
     * Modifier le libellé/les dates reste réservé à initiatives.gerer (ce
     * sont coordination/suivi-évaluation qui structurent le projet). Le
     * statut n'est plus modifiable ici : il est entièrement calculé
     * (StatutInitiativeCalculator) à partir des dates et de l'avancement
     * des tâches — § décision produit.
     */
    #[Route('/initiative-activites/{uuid}', name: 'app_initiative_activite_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifierActivite(string $uuid, Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'initiatives.gerer');
        $activite = $this->trouverActivite($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle']) && '' !== $donnees['libelle']) {
            $activite->setLibelle($donnees['libelle']);
        }
        if (\array_key_exists('dateDebut', $donnees)) {
            $activite->setDateDebut(\is_string($donnees['dateDebut']) ? new \DateTimeImmutable($donnees['dateDebut']) : null);
        }
        if (\array_key_exists('echeance', $donnees)) {
            $activite->setEcheance(\is_string($donnees['echeance']) ? new \DateTimeImmutable($donnees['echeance']) : null);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($activite->getInitiative()));
    }

    #[Route('/initiative-activites/{uuid}', name: 'app_initiative_activite_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimerActivite(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'initiatives.gerer');

        $activite = $this->trouverActivite($uuid);
        $activite->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /** Le(s) responsable(s) de l'activité peuvent ajouter des tâches sans détenir initiatives.gerer (§ décision produit). */
    #[Route('/taches-initiative', name: 'app_tache_initiative_creer', methods: ['POST'])]
    public function creerTache(Request $request): Response
    {
        /** @var CreerTacheInitiativeRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerTacheInitiativeRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $activite = $this->activites->findOneBy(['uuid' => $this->uuidOuNull($donnees->initiativeActivite)]);
        if (null === $activite) {
            return ProblemResponseFactory::creer(422, 'Activité introuvable');
        }

        $this->exigerResponsableOuGerer($activite);

        $responsable = null;
        if (null !== $donnees->responsable) {
            $responsable = $this->utilisateurs->findOneBy(['uuid' => $this->uuidOuNull($donnees->responsable)]);
            if (null === $responsable) {
                return ProblemResponseFactory::creer(422, 'Compte utilisateur introuvable pour le responsable');
            }
        }

        $ordre = $donnees->ordre ?? (\count($this->taches->listerNonSupprimees($activite)) + 1);
        $tache = new TacheInitiative($activite, $donnees->libelle, $ordre);
        $tache->setResponsable($responsable);

        $this->em->persist($tache);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($activite->getInitiative()), 201);
    }

    #[Route('/taches-initiative/{uuid}', name: 'app_tache_initiative_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifierTache(string $uuid, Request $request): JsonResponse
    {
        $tache = $this->trouverTache($uuid);
        $this->exigerResponsableOuGerer($tache->getInitiativeActivite(), $tache);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle']) && '' !== $donnees['libelle']) {
            $tache->setLibelle($donnees['libelle']);
        }
        if (\array_key_exists('terminee', $donnees) && \is_bool($donnees['terminee'])) {
            $tache->definirTerminee($donnees['terminee']);
        }
        if (\array_key_exists('responsable', $donnees)) {
            if (null === $donnees['responsable']) {
                $tache->setResponsable(null);
            } elseif (\is_string($donnees['responsable'])) {
                $responsable = $this->utilisateurs->findOneBy(['uuid' => $this->uuidOuNull($donnees['responsable'])]);
                if (null === $responsable) {
                    return ProblemResponseFactory::creer(422, 'Compte utilisateur introuvable pour le responsable');
                }
                $tache->setResponsable($responsable);
            }
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($tache->getInitiativeActivite()->getInitiative()));
    }

    #[Route('/taches-initiative/{uuid}', name: 'app_tache_initiative_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimerTache(string $uuid): Response
    {
        $tache = $this->trouverTache($uuid);
        $this->exigerResponsableOuGerer($tache->getInitiativeActivite());

        $tache->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /** Observation libre sur une activité ou une tâche — même règle d'autorisation que la gestion des tâches (§ décision produit). */
    #[Route('/initiative-notes', name: 'app_initiative_note_creer', methods: ['POST'])]
    public function creerNote(Request $request): Response
    {
        /** @var CreerNoteInitiativeRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerNoteInitiativeRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $cible = $this->resoudreCible($donnees->entiteType, $donnees->entiteUuid);
        if (null === $cible) {
            return ProblemResponseFactory::creer(422, 'Entité introuvable');
        }

        $this->exigerResponsableOuGerer($this->activiteDepuisCible($cible), $cible instanceof TacheInitiative ? $cible : null);

        $utilisateur = $this->security->getUser();
        $note = new NoteInitiative($donnees->entiteType, (int) $cible->getId(), $donnees->contenu, $utilisateur instanceof Utilisateur ? $utilisateur : null);
        $this->em->persist($note);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($this->initiativeDepuisCible($cible)), 201);
    }

    /** Pièce jointe sur une activité ou une tâche — même stockage/limite que RapportController::transmettre() (10 Mo, disque local). */
    #[Route('/initiative-pieces-jointes', name: 'app_initiative_piece_jointe_creer', methods: ['POST'])]
    public function creerPieceJointe(Request $request): Response
    {
        $entiteType = (string) $request->request->get('entiteType');
        $entiteUuid = (string) $request->request->get('entiteUuid');
        if (!\in_array($entiteType, ['initiative_activite', 'tache_initiative'], true) || '' === $entiteUuid) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'entiteType et entiteUuid sont requis.');
        }

        $cible = $this->resoudreCible($entiteType, $entiteUuid);
        if (null === $cible) {
            return ProblemResponseFactory::creer(422, 'Entité introuvable');
        }

        $this->exigerResponsableOuGerer($this->activiteDepuisCible($cible), $cible instanceof TacheInitiative ? $cible : null);

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

        $nature = TypeDocumentRequete::lire($request);
        if ($nature instanceof JsonResponse) {
            return $nature;
        }

        $visibilite = VisibilitePieceJointe::tryFrom((string) $request->request->get('visibilite', 'tous'));
        if (null === $visibilite) {
            return ProblemResponseFactory::creer(422, 'Visibilité invalide');
        }

        $agents = [];
        if (VisibilitePieceJointe::AGENTS_SPECIFIQUES === $visibilite) {
            /** @var list<string> $agentsUuid */
            $agentsUuid = $request->request->all('agentsUuid');
            if ([] === $agentsUuid) {
                return ProblemResponseFactory::creer(422, 'Agent(s) manquant(s)', 'Choisissez au moins un agent pour la visibilité "Agents spécifiques".');
            }
            foreach ($agentsUuid as $agentUuid) {
                $agent = $this->utilisateurs->findOneBy(['uuid' => $this->uuidOuNull($agentUuid)]);
                if (null === $agent) {
                    return ProblemResponseFactory::creer(422, 'Compte utilisateur introuvable pour un agent');
                }
                $agents[] = $agent;
            }
        }

        $repertoire = $this->projectDir.'/var/uploads/initiatives';
        $this->filesystem->mkdir($repertoire);
        $nomStocke = Uuid::v4()->toRfc4122().'-'.$fichier->getClientOriginalName();
        $nomOriginal = $fichier->getClientOriginalName();
        $mime = $fichier->getClientMimeType() ?? 'application/octet-stream';
        $fichier->move($repertoire, $nomStocke);

        $pieceJointe = new PieceJointe(
            $entiteType,
            (int) $cible->getId(),
            $nomOriginal,
            'var/uploads/initiatives/'.$nomStocke,
            $mime,
            $tailleOctets,
            $utilisateur,
        );
        $pieceJointe->qualifier(...$nature);
        $pieceJointe->definirVisibilite($visibilite, $agents);
        $this->em->persist($pieceJointe);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($this->initiativeDepuisCible($cible)), 201);
    }

    private function resoudreCible(string $entiteType, string $entiteUuid): InitiativeActivite|TacheInitiative|null
    {
        $uuidObjet = $this->uuidOuNull($entiteUuid);
        if (null === $uuidObjet) {
            return null;
        }

        return match ($entiteType) {
            'initiative_activite' => $this->activites->findOneBy(['uuid' => $uuidObjet]),
            'tache_initiative' => $this->taches->findOneBy(['uuid' => $uuidObjet]),
            default => null,
        };
    }

    private function activiteDepuisCible(InitiativeActivite|TacheInitiative $cible): InitiativeActivite
    {
        return $cible instanceof TacheInitiative ? $cible->getInitiativeActivite() : $cible;
    }

    private function initiativeDepuisCible(InitiativeActivite|TacheInitiative $cible): Initiative
    {
        return $this->activiteDepuisCible($cible)->getInitiative();
    }

    /**
     * Le responsable d'une tâche précise (distinct des responsables de son
     * activité parente) doit lui aussi pouvoir la gérer — sinon un
     * responsable de tâche qui n'est pas également responsable de
     * l'activité entière n'a aucun moyen de renseigner sa progression.
     */
    private function exigerResponsableOuGerer(InitiativeActivite $activite, ?TacheInitiative $tache = null): void
    {
        $this->exigerConsultation();
        $utilisateur = $this->security->getUser();
        $peutTout = $utilisateur instanceof Utilisateur
            && $this->permissionsResolver->pour($utilisateur)->estGlobale('initiatives.gerer');
        $estResponsableActivite = $utilisateur instanceof Utilisateur && $activite->estResponsable($utilisateur);
        $estResponsableTache = null !== $tache && $utilisateur instanceof Utilisateur && $tache->getResponsable() === $utilisateur;

        if (!$peutTout && !$estResponsableActivite && !$estResponsableTache) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Module Projets réservé à l'UGP (décision du 26/10/2026) : ni les
     * points focaux ni le profil lecteur n'y ont accès, en lecture comme en
     * écriture — y compris s'ils avaient été désignés responsables.
     */
    private function exigerConsultation(): void
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, self::CONSULTATION);
    }

    private function trouverInitiative(string $uuid): Initiative
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $initiative = null !== $uuidObjet ? $this->initiatives->findOneBy(['uuid' => $uuidObjet]) : null;

        return $initiative ?? throw new NotFoundHttpException('Projet introuvable.');
    }

    private function trouverActivite(string $uuid): InitiativeActivite
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $activite = null !== $uuidObjet ? $this->activites->findOneBy(['uuid' => $uuidObjet]) : null;

        return $activite ?? throw new NotFoundHttpException('Activité introuvable.');
    }

    private function trouverTache(string $uuid): TacheInitiative
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $tache = null !== $uuidObjet ? $this->taches->findOneBy(['uuid' => $uuidObjet]) : null;

        return $tache ?? throw new NotFoundHttpException('Tâche introuvable.');
    }
}
