<?php

declare(strict_types=1);

namespace App\Controller;

use App\Courriel\ServiceCourriel;
use App\Entity\PieceJointe;
use App\Entity\RapportPeriodique;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Enum\PeriodeTrimestre;
use App\Enum\StatutRapport;
use App\Http\ProblemResponseFactory;
use App\Http\RapportPeriodiqueNormalizer;
use App\Rapport\DestinatairesRelanceResolver;
use App\Repository\ExerciceRepository;
use App\Repository\ProjetRepository;
use App\Repository\RapportPeriodiqueRepository;
use App\Repository\StructureRepository;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Le statut "en retard" n'existe jamais en base (§ 5.5, § 4.1 CLAUDE.md) :
 * transmettre/relancer n'ont donc jamais à le "sortir" d'un état retard,
 * seulement à agir sur le statut réellement stocké.
 */
#[Route('/api/v1/rapports')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RapportController
{
    use ApiControllerHelpersTrait;

    private const TAILLE_MAX_OCTETS = 10 * 1024 * 1024;

    public function __construct(
        private readonly RapportPeriodiqueRepository $rapports,
        private readonly ExerciceRepository $exercices,
        private readonly ProjetRepository $projets,
        private readonly StructureRepository $structures,
        private readonly DestinatairesRelanceResolver $destinataires,
        private readonly RapportPeriodiqueNormalizer $normalizer,
        private readonly ServiceCourriel $courriels,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'app_rapport_liste', methods: ['GET'])]
    public function liste(Request $request): JsonResponse
    {
        $exercice = null;
        $anneeBrute = $request->query->get('exercice');
        if (\is_string($anneeBrute) && '' !== $anneeBrute) {
            $projet = $this->projets->findOneBy([]);
            $exercice = null !== $projet ? $this->exercices->trouverParAnnee($projet, (int) $anneeBrute) : null;
        }

        $periode = null;
        $periodeBrute = $request->query->get('periode');
        if (\is_string($periodeBrute) && '' !== $periodeBrute) {
            $periode = PeriodeTrimestre::tryFrom($periodeBrute);
            if (null === $periode) {
                return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'periode doit valoir T1, T2, T3 ou T4.');
            }
        }

        // "retard" est une valeur virtuelle (§ 5.5) : elle filtre après coup
        // sur le champ dérivé, jamais sur une colonne statut réelle.
        $statutBrut = $request->query->get('statut');
        $filtreRetard = 'retard' === $statutBrut;
        $statut = null;
        if (\is_string($statutBrut) && '' !== $statutBrut && !$filtreRetard) {
            $statut = StatutRapport::tryFrom($statutBrut);
            if (null === $statut) {
                return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'statut invalide.');
            }
        }

        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rapports = $this->rapports->filtrer($exercice, $periode, $filtreRetard ? StatutRapport::ATTENDU : $statut);
        if ($filtreRetard) {
            $rapports = array_values(array_filter($rapports, static fn (RapportPeriodique $r) => $r->estEnRetard($maintenant)));
        }

        return new JsonResponse([
            'donnees' => array_map(fn (RapportPeriodique $r) => $this->normalizer->normaliser($r, $maintenant), $rapports),
        ]);
    }

    /** points_focaux.gerer (§ 7.2), portée-aware : un point focal transmet pour sa propre structure, la coordination pour toutes. */
    #[Route('/{uuid}/transmettre', name: 'app_rapport_transmettre', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function transmettre(string $uuid, Request $request): Response
    {
        $rapport = $this->trouver($uuid);
        $this->exigerPermission($this->security, 'points_focaux.gerer', $rapport);

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

        $repertoire = $this->projectDir.'/var/uploads/rapports';
        $this->filesystem->mkdir($repertoire);
        $nomStocke = Uuid::v4()->toRfc4122().'-'.$fichier->getClientOriginalName();
        $nomOriginal = $fichier->getClientOriginalName();
        $mime = $fichier->getClientMimeType() ?? 'application/octet-stream';
        $fichier->move($repertoire, $nomStocke);

        $pieceJointe = new PieceJointe(
            'rapport_periodique',
            (int) $rapport->getId(),
            $nomOriginal,
            'var/uploads/rapports/'.$nomStocke,
            $mime,
            $tailleOctets,
            $utilisateur,
        );
        $this->em->persist($pieceJointe);

        $rapport->transmettre($pieceJointe);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($rapport, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    /**
     * Action groupée (§ 2.5) : toujours en portée globale — relancer les
     * retardataires est une action de pilotage de la coordination, pas
     * quelque chose qu'un point focal ferait sur sa propre structure.
     */
    #[Route('/relancer', name: 'app_rapport_relancer', methods: ['POST'])]
    public function relancer(Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'points_focaux.gerer');

        /** @var array<string, mixed> $corps */
        $corps = json_decode($request->getContent(), true) ?? [];
        $structureUuids = $corps['structures'] ?? [];
        if (!\is_array($structureUuids) || [] === $structureUuids) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'structures doit être un tableau non vide d\'uuid.');
        }

        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $relances = 0;
        $courriels = 0;
        $sansDestinataire = 0;

        foreach ($structureUuids as $structureUuid) {
            if (!\is_string($structureUuid)) {
                continue;
            }
            $structure = $this->structures->findOneBy(['uuid' => $this->uuidOuNull($structureUuid)]);
            if (null === $structure) {
                continue;
            }
            $destinataires = $this->destinataires->pour($structure);
            foreach ($this->rapports->findBy(['structure' => $structure, 'statut' => StatutRapport::ATTENDU]) as $rapport) {
                if (!$rapport->estEnRetard($maintenant)) {
                    continue;
                }
                $rapport->relancer($maintenant);
                ++$relances;

                if ([] === $destinataires) {
                    ++$sansDestinataire;
                    continue;
                }
                foreach ($destinataires as $destinataire) {
                    if ($this->courriels->relanceRapport($rapport, $destinataire, $maintenant)) {
                        ++$courriels;
                    }
                }
            }
        }

        $this->em->flush();

        // La relance reste horodatée même sans courriel parti (adresse
        // manquante ou SMTP indisponible) : l'action de pilotage est
        // enregistrée, le compte de courriels dit seulement ce qui a
        // effectivement pu être notifié.
        return new JsonResponse(['relances' => $relances, 'courriels' => $courriels, 'sansDestinataire' => $sansDestinataire]);
    }

    private function trouver(string $uuid): RapportPeriodique
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $rapport = null !== $uuidObjet ? $this->rapports->findOneBy(['uuid' => $uuidObjet]) : null;

        return $rapport ?? throw new NotFoundHttpException('Rapport introuvable.');
    }
}
