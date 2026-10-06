<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activite;
use App\Entity\Contrat;
use App\Entity\EffectifEnqueteurs;
use App\Entity\Personnel;
use App\Enum\Sexe;
use App\Http\PersonnelNormalizer;
use App\Http\ProblemResponseFactory;
use App\Repository\ActiviteRepository;
use App\Repository\ContratRepository;
use App\Repository\EffectifEnqueteursRepository;
use App\Repository\PersonnelRepository;
use App\Repository\StructureRepository;
use App\Security\PermissionsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi des ressources humaines du projet (§ 10 et § 11 du CR du
 * 22/09/2026) : personnel technique nominatif, contrats, et effectifs
 * d'enquêteurs agrégés par opération.
 *
 * Deux permissions, toutes deux en portée globale : rh.consulter pour lire,
 * rh.gerer pour écrire. Elles sont détenues par le coordonnateur et le
 * responsable suivi-évaluation — les données de contrat ne regardent pas les
 * structures bénéficiaires.
 *
 * Aucune information salariale n'est acceptée en entrée ni renvoyée : la
 * réunion les a écartées comme sensibles (§ 14).
 */
#[Route('/api/v1/rh')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RessourcesHumainesController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly PersonnelRepository $personnels,
        private readonly ContratRepository $contrats,
        private readonly EffectifEnqueteursRepository $effectifs,
        private readonly ActiviteRepository $activites,
        private readonly StructureRepository $structures,
        private readonly PersonnelNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    #[Route('/personnels', name: 'app_rh_personnels_liste', methods: ['GET'])]
    public function listerPersonnels(): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.consulter');
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new JsonResponse([
            'donnees' => array_map(
                fn (Personnel $p) => $this->normalizer->normaliser($p, $maintenant),
                $this->personnels->listerNonSupprimes(),
            ),
        ]);
    }

    #[Route('/personnels/{uuid}', name: 'app_rh_personnel_detail', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function detailPersonnel(string $uuid): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.consulter');

        return new JsonResponse($this->normalizer->normaliser(
            $this->trouverPersonnel($uuid),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }

    #[Route('/personnels', name: 'app_rh_personnel_creer', methods: ['POST'])]
    public function creerPersonnel(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        $nom = $this->chaineOuNull($donnees, 'nom');
        $prenom = $this->chaineOuNull($donnees, 'prenom');
        $fonction = $this->chaineOuNull($donnees, 'fonction');
        $sexe = Sexe::tryFrom((string) ($donnees['sexe'] ?? ''));

        if (null === $nom || null === $prenom || null === $fonction) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'Le nom, le prénom et la fonction sont requis.');
        }
        if (null === $sexe) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'Le sexe doit valoir M ou F.');
        }

        $personnel = new Personnel($nom, $prenom, $sexe, $fonction);
        $erreur = $this->appliquer($personnel, $donnees)
            ?? $this->appliquerContratDepuisFiche($personnel, $donnees);
        if (null !== $erreur) {
            return $erreur;
        }

        $this->em->persist($personnel);
        $this->em->flush();

        return new JsonResponse(
            $this->normalizer->normaliser($personnel, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            201,
        );
    }

    #[Route('/personnels/{uuid}', name: 'app_rh_personnel_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifierPersonnel(string $uuid, Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');
        $personnel = $this->trouverPersonnel($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('nom', $donnees) && null !== ($nom = $this->chaineOuNull($donnees, 'nom'))) {
            $personnel->setNom($nom);
        }
        if (\array_key_exists('prenom', $donnees) && null !== ($prenom = $this->chaineOuNull($donnees, 'prenom'))) {
            $personnel->setPrenom($prenom);
        }
        if (\array_key_exists('fonction', $donnees) && null !== ($fonction = $this->chaineOuNull($donnees, 'fonction'))) {
            $personnel->setFonction($fonction);
        }
        if (\array_key_exists('sexe', $donnees)) {
            $sexe = Sexe::tryFrom((string) $donnees['sexe']);
            if (null === $sexe) {
                return ProblemResponseFactory::creer(422, 'Données invalides', 'Le sexe doit valoir M ou F.');
            }
            $personnel->setSexe($sexe);
        }

        $erreur = $this->appliquer($personnel, $donnees)
            ?? $this->appliquerContratDepuisFiche($personnel, $donnees);
        if (null !== $erreur) {
            return $erreur;
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($personnel, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    /** Suppression logique : un agent retiré de l'effectif a laissé des contrats, qui restent lisibles pour la capitalisation (§ 11). */
    #[Route('/personnels/{uuid}', name: 'app_rh_personnel_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimerPersonnel(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');

        $personnel = $this->trouverPersonnel($uuid);
        $personnel->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /** Un renouvellement est un nouveau contrat, jamais une prolongation de l'ancien (§ 10 du CR). */
    #[Route('/personnels/{uuid}/contrats', name: 'app_rh_contrat_creer', methods: ['POST'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function creerContrat(string $uuid, Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');
        $personnel = $this->trouverPersonnel($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];
        $dateDebut = $this->chaineOuNull($donnees, 'dateDebut');
        if (null === $dateDebut) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'La date de début est requise.');
        }

        $contrat = new Contrat($personnel, new \DateTimeImmutable($dateDebut));
        $erreur = $this->appliquerContrat($contrat, $donnees);
        if (null !== $erreur) {
            return $erreur;
        }

        $this->em->persist($contrat);
        $this->em->flush();

        return new JsonResponse(
            $this->normalizer->normaliser($personnel, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            201,
        );
    }

    #[Route('/contrats/{uuid}', name: 'app_rh_contrat_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifierContrat(string $uuid, Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');
        $contrat = $this->trouverContrat($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];
        if (\array_key_exists('dateDebut', $donnees) && null !== ($debut = $this->chaineOuNull($donnees, 'dateDebut'))) {
            $contrat->setDateDebut(new \DateTimeImmutable($debut));
        }

        $erreur = $this->appliquerContrat($contrat, $donnees);
        if (null !== $erreur) {
            return $erreur;
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliserContrat($contrat, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    #[Route('/contrats/{uuid}', name: 'app_rh_contrat_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimerContrat(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');

        $contrat = $this->trouverContrat($uuid);
        $contrat->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /** Échéances à venir (et dépassées) — alimente l'encart de rappel du tableau de bord RH. */
    #[Route('/echeances', name: 'app_rh_echeances', methods: ['GET'])]
    public function echeances(Request $request): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.consulter');

        $jours = max(1, min(365, $request->query->getInt('jours', 90)));
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $limite = $maintenant->modify(\sprintf('+%d days', $jours));

        $donnees = [];
        foreach ($this->contrats->echeantsAvant($limite) as $contrat) {
            $personnel = $contrat->getPersonnel();
            if ($personnel->estSupprime()) {
                continue;
            }
            $donnees[] = [
                'personnel' => [
                    'uuid' => $personnel->getUuid()->toRfc4122(),
                    'nomComplet' => $personnel->getNomComplet(),
                    'fonction' => $personnel->getFonction(),
                ],
                'contrat' => $this->normalizer->normaliserContrat($contrat, $maintenant),
            ];
        }

        return new JsonResponse(['jours' => $jours, 'donnees' => $donnees]);
    }

    #[Route('/effectifs-enqueteurs', name: 'app_rh_effectifs_liste', methods: ['GET'])]
    public function listerEffectifs(): JsonResponse
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.consulter');

        return new JsonResponse([
            'donnees' => array_map(
                $this->normalizer->normaliserEffectif(...),
                $this->effectifs->listerNonSupprimes(),
            ),
        ]);
    }

    #[Route('/effectifs-enqueteurs', name: 'app_rh_effectif_creer', methods: ['POST'])]
    public function creerEffectif(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];
        $activite = $this->activiteDepuis($donnees);
        if (null === $activite) {
            return ProblemResponseFactory::creer(422, 'Activité introuvable', "L'opération de rattachement est requise.");
        }

        $annee = (int) ($donnees['annee'] ?? 0);
        if ($annee < 2000 || $annee > 2100) {
            return ProblemResponseFactory::creer(422, 'Données invalides', "L'année est requise.");
        }

        $effectif = new EffectifEnqueteurs($activite, $annee);
        $erreur = $this->appliquerEffectif($effectif, $donnees);
        if (null !== $erreur) {
            return $erreur;
        }

        $this->em->persist($effectif);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliserEffectif($effectif), 201);
    }

    #[Route('/effectifs-enqueteurs/{uuid}', name: 'app_rh_effectif_modifier', methods: ['PATCH'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function modifierEffectif(string $uuid, Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');
        $effectif = $this->trouverEffectif($uuid);

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];
        if (\array_key_exists('activite', $donnees)) {
            $activite = $this->activiteDepuis($donnees);
            if (null === $activite) {
                return ProblemResponseFactory::creer(422, 'Activité introuvable');
            }
            $effectif->setActivite($activite);
        }
        if (\array_key_exists('annee', $donnees)) {
            $annee = (int) $donnees['annee'];
            if ($annee < 2000 || $annee > 2100) {
                return ProblemResponseFactory::creer(422, 'Données invalides', "L'année est invalide.");
            }
            $effectif->setAnnee($annee);
        }

        $erreur = $this->appliquerEffectif($effectif, $donnees);
        if (null !== $erreur) {
            return $erreur;
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliserEffectif($effectif));
    }

    #[Route('/effectifs-enqueteurs/{uuid}', name: 'app_rh_effectif_supprimer', methods: ['DELETE'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'])]
    public function supprimerEffectif(string $uuid): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'rh.gerer');

        $effectif = $this->trouverEffectif($uuid);
        $effectif->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /** @param array<string, mixed> $donnees */
    private function appliquer(Personnel $personnel, array $donnees): ?Response
    {
        foreach (['profil', 'specialite', 'email', 'telephone'] as $champ) {
            if (\array_key_exists($champ, $donnees)) {
                $valeur = \is_string($donnees[$champ]) && '' !== trim($donnees[$champ]) ? trim($donnees[$champ]) : null;
                match ($champ) {
                    'profil' => $personnel->setProfil($valeur),
                    'specialite' => $personnel->setSpecialite($valeur),
                    'email' => $personnel->setEmail($valeur),
                    default => $personnel->setTelephone($valeur),
                };
            }
        }

        if (\array_key_exists('structure', $donnees)) {
            if (null === $donnees['structure']) {
                $personnel->setStructure(null);
            } else {
                $structure = $this->structures->findOneBy(['uuid' => $this->uuidOuNull((string) $donnees['structure'])]);
                if (null === $structure) {
                    return ProblemResponseFactory::creer(422, 'Structure introuvable');
                }
                $personnel->setStructure($structure);
            }
        }

        if (\array_key_exists('activite', $donnees)) {
            if (null === $donnees['activite']) {
                $personnel->setActivite(null);
            } else {
                $activite = $this->activiteDepuis($donnees);
                if (null === $activite) {
                    return ProblemResponseFactory::creer(422, 'Activité introuvable');
                }
                $personnel->setActivite($activite);
            }
        }

        return null;
    }

    /**
     * Période contractuelle saisie depuis la fiche de l'agent, sous la clé
     * `contrat` — l'UGP veut les dates sous les yeux au moment où elle crée
     * ou corrige un agent (demande du 05/10/2026), sans passer par l'écran
     * des contrats.
     *
     * À la création, elle ouvre le premier contrat. À la modification, elle
     * corrige celui en cours — ce n'est pas un renouvellement : renouveler
     * reste POST /personnels/{uuid}/contrats, qui ajoute une ligne et
     * conserve la précédente (§ 10 du CR du 22/09/2026).
     *
     * @param array<string, mixed> $donnees
     */
    private function appliquerContratDepuisFiche(Personnel $personnel, array $donnees): ?Response
    {
        if (!\array_key_exists('contrat', $donnees) || !\is_array($donnees['contrat'])) {
            return null;
        }

        /** @var array<string, mixed> $periode */
        $periode = $donnees['contrat'];
        $debut = $this->chaineOuNull($periode, 'dateDebut');
        $contrat = $personnel->getContratCourant();

        if (null === $contrat) {
            if (null === $debut) {
                return ProblemResponseFactory::creer(422, 'Données invalides', 'La date de début de contrat est requise.');
            }
            $contrat = new Contrat($personnel, new \DateTimeImmutable($debut));
            $this->em->persist($contrat);
        } elseif (null !== $debut) {
            $contrat->setDateDebut(new \DateTimeImmutable($debut));
        }

        return $this->appliquerContrat($contrat, $periode);
    }

    /** @param array<string, mixed> $donnees */
    private function appliquerContrat(Contrat $contrat, array $donnees): ?Response
    {
        if (\array_key_exists('dateFin', $donnees)) {
            $fin = $this->chaineOuNull($donnees, 'dateFin');
            $contrat->setDateFin(null === $fin ? null : new \DateTimeImmutable($fin));
        }
        if (\array_key_exists('type', $donnees)) {
            // Champ absent du corps : le contrat garde sa valeur par défaut
            // (contrat de prestation). Présent mais vide : l'UGP le vide
            // volontairement.
            $contrat->setType($this->chaineOuNull($donnees, 'type'));
        }
        if (\array_key_exists('observation', $donnees)) {
            $contrat->setObservation($this->chaineOuNull($donnees, 'observation'));
        }

        $fin = $contrat->getDateFin();
        if (null !== $fin && $fin < $contrat->getDateDebut()) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'La date de fin ne peut pas précéder la date de début.');
        }

        return null;
    }

    /** @param array<string, mixed> $donnees */
    private function appliquerEffectif(EffectifEnqueteurs $effectif, array $donnees): ?Response
    {
        if (\array_key_exists('libelle', $donnees)) {
            $effectif->setLibelle($this->chaineOuNull($donnees, 'libelle'));
        }
        if (\array_key_exists('nombreFemmes', $donnees) || \array_key_exists('nombreHommes', $donnees)) {
            $effectif->definirEffectifs(
                (int) ($donnees['nombreFemmes'] ?? $effectif->getNombreFemmes()),
                (int) ($donnees['nombreHommes'] ?? $effectif->getNombreHommes()),
            );
        }

        // Les deux bornes sont posées ensemble : l'entité refuse une fin
        // antérieure au début, et une borne absente du corps garde sa valeur.
        if (\array_key_exists('dateDebut', $donnees) || \array_key_exists('dateFin', $donnees)) {
            $debut = \array_key_exists('dateDebut', $donnees)
                ? $this->dateOuNull($donnees, 'dateDebut')
                : $effectif->getDateDebut();
            $fin = \array_key_exists('dateFin', $donnees)
                ? $this->dateOuNull($donnees, 'dateFin')
                : $effectif->getDateFin();

            try {
                $effectif->definirPeriode($debut, $fin);
            } catch (\InvalidArgumentException $e) {
                return ProblemResponseFactory::creer(422, 'Données invalides', $e->getMessage());
            }
        }

        return null;
    }

    /** @param array<string, mixed> $donnees */
    private function dateOuNull(array $donnees, string $champ): ?\DateTimeImmutable
    {
        $valeur = $this->chaineOuNull($donnees, $champ);

        return null === $valeur ? null : new \DateTimeImmutable($valeur);
    }

    /** @param array<string, mixed> $donnees */
    private function activiteDepuis(array $donnees): ?Activite
    {
        $uuid = $this->chaineOuNull($donnees, 'activite');

        return null === $uuid ? null : $this->activites->findOneBy(['uuid' => $this->uuidOuNull($uuid)]);
    }

    /** @param array<string, mixed> $donnees */
    private function chaineOuNull(array $donnees, string $champ): ?string
    {
        $valeur = $donnees[$champ] ?? null;

        return \is_string($valeur) && '' !== trim($valeur) ? trim($valeur) : null;
    }

    private function trouverPersonnel(string $uuid): Personnel
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $personnel = null !== $uuidObjet ? $this->personnels->findOneBy(['uuid' => $uuidObjet]) : null;

        if (null === $personnel || $personnel->estSupprime()) {
            throw new NotFoundHttpException('Agent introuvable.');
        }

        return $personnel;
    }

    private function trouverContrat(string $uuid): Contrat
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $contrat = null !== $uuidObjet ? $this->contrats->findOneBy(['uuid' => $uuidObjet]) : null;

        if (null === $contrat || $contrat->estSupprime()) {
            throw new NotFoundHttpException('Contrat introuvable.');
        }

        return $contrat;
    }

    private function trouverEffectif(string $uuid): EffectifEnqueteurs
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $effectif = null !== $uuidObjet ? $this->effectifs->findOneBy(['uuid' => $uuidObjet]) : null;

        if (null === $effectif || $effectif->estSupprime()) {
            throw new NotFoundHttpException('Effectif introuvable.');
        }

        return $effectif;
    }
}
