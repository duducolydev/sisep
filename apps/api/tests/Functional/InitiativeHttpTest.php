<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Initiative;
use App\Entity\InitiativeActivite;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\TacheInitiative;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Suivi des projets : initiatives.gerer n'existe qu'en portée globale, sauf
 * pour gérer les tâches d'une InitiativeActivite, ouvert en plus au(x)
 * responsable(s) assigné(s) (§ décision produit, portée par personne).
 */
final class InitiativeHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testCreationActiviteEtTachesAvancementCalcule(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/initiatives', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'libelle' => 'Modernisation du portail',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $initiativeUuid = $this->corps($client)['uuid'];

            $client->request('POST', '/api/v1/initiative-activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiative' => $initiativeUuid,
                'libelle' => 'Refonte technique',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $activiteUuid = $this->corps($client)['activites'][0]['uuid'];

            $client->request('POST', '/api/v1/taches-initiative', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiativeActivite' => $activiteUuid,
                'libelle' => 'Cahier des charges',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $corps = $this->corps($client);
            $tache1Uuid = $corps['activites'][0]['taches'][0]['uuid'];
            // json_encode() sans JSON_PRESERVE_ZERO_FRACTION rend 0.0 comme 0 — assertSame échouerait après l'aller-retour JSON sans que ce soit un vrai bug.
            self::assertEqualsWithDelta(0.0, $corps['activites'][0]['avancement'], 0.0001, 'Aucune tâche faite sur 1 → avancement 0.');

            $client->request('POST', '/api/v1/taches-initiative', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiativeActivite' => $activiteUuid,
                'libelle' => 'Développement',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $client->request('PATCH', "/api/v1/taches-initiative/{$tache1Uuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'terminee' => true,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertSame(0.5, $this->corps($client)['activites'][0]['avancement'], '1 tâche faite sur 2 → avancement 0,5.');
        } finally {
            $this->rollback();
        }
    }

    public function testActiviteSansTacheAvancementNull(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            // L'activité de la fixture partagée a déjà une tâche (§ demarrer) —
            // en créer une nouvelle, sans tâche, pour ce cas précis.
            $client->request('POST', '/api/v1/initiative-activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiative' => $fixture['initiative']->getUuid()->toRfc4122(),
                'libelle' => 'Sans tâche',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $activites = $this->corps($client)['activites'];
            $activiteSansTache = current(array_filter($activites, static fn ($a) => 'Sans tâche' === $a['libelle']));
            self::assertNull($activiteSansTache['avancement'], 'Division par zéro → null, jamais 0.');
        } finally {
            $this->rollback();
        }
    }

    public function testGererUneTacheRefuseSansPermissionNiResponsable(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['tiers']->getEmail());

            $client->request('PATCH', "/api/v1/taches-initiative/{$fixture['tache']->getUuid()->toRfc4122()}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'terminee' => true,
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    public function testResponsableAssigneGereLesTachesSansInitiativesGerer(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['responsable']->getEmail());

            $client->request('PATCH', "/api/v1/taches-initiative/{$fixture['tache']->getUuid()->toRfc4122()}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'terminee' => true,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful('Le responsable de l\'activité peut gérer ses tâches sans initiatives.gerer.');
        } finally {
            $this->rollback();
        }
    }

    /** Le responsable d'une tâche précise (pas de l'activité entière) doit pouvoir gérer cette tâche — sinon aucun moyen pour lui de renseigner sa progression (§ décision produit). */
    public function testResponsableDUneTacheSeuleGereCetteTacheSansEtreResponsableDeLActivite(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            // Activité sans $fixture['responsable'] parmi ses responsables — seule la tâche cible $fixture['responsableTacheSeule'].
            $client->request('POST', '/api/v1/initiative-activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiative' => $fixture['initiative']->getUuid()->toRfc4122(),
                'libelle' => 'Sans responsable dédié',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $activite = $this->trouverActiviteParLibelle($client, 'Sans responsable dédié');

            $client->request('POST', '/api/v1/taches-initiative', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiativeActivite' => $activite['uuid'],
                'libelle' => 'Rédaction',
                'responsable' => $fixture['responsableTacheSeule']->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $tacheUuid = $this->trouverActiviteParLibelle($client, 'Sans responsable dédié')['taches'][0]['uuid'];

            $this->connecter($client, $fixture['responsableTacheSeule']->getEmail());

            $client->request('PATCH', "/api/v1/taches-initiative/{$tacheUuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'terminee' => true,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful('Le responsable de la tâche peut la gérer même sans être responsable de son activité.');

            $client->request('POST', '/api/v1/initiative-notes', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'entiteType' => 'tache_initiative',
                'entiteUuid' => $tacheUuid,
                'contenu' => 'Rédaction terminée.',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201, 'Idem pour ajouter une observation sur sa propre tâche.');
        } finally {
            $this->rollback();
        }
    }

    /** Le statut d'une activité n'est plus jamais saisi : dès que sa seule tâche passe "terminée", l'activité l'est aussi, sans aucun PATCH sur l'activité elle-même. */
    public function testActiviteDevientTermineeAutomatiquementQuandSesTachesLeSont(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('PATCH', "/api/v1/taches-initiative/{$fixture['tache']->getUuid()->toRfc4122()}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'terminee' => true,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertSame('terminee', $this->corps($client)['activites'][0]['statut']);
            // json_encode() sans JSON_PRESERVE_ZERO_FRACTION rend 1.0 comme 1 — assertSame échouerait après l'aller-retour JSON sans que ce soit un vrai bug.
            self::assertEqualsWithDelta(1.0, $this->corps($client)['activites'][0]['avancement'], 0.0001);
        } finally {
            $this->rollback();
        }
    }

    public function testResponsableAjouteUneObservationEtUnTiersEstRefuse(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['responsable']->getEmail());
            $client->request('POST', '/api/v1/initiative-notes', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'entiteType' => 'initiative_activite',
                'entiteUuid' => $fixture['activite']->getUuid()->toRfc4122(),
                'contenu' => 'Réunion de cadrage tenue.',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $notes = $this->corps($client)['activites'][0]['notes'];
            self::assertCount(1, $notes);
            self::assertSame('Réunion de cadrage tenue.', $notes[0]['contenu']);

            // Une observation sur la tâche elle-même passe par l'activité parente pour l'autorisation.
            $client->request('POST', '/api/v1/initiative-notes', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'entiteType' => 'tache_initiative',
                'entiteUuid' => $fixture['tache']->getUuid()->toRfc4122(),
                'contenu' => 'Cadrage terminé.',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            self::assertCount(1, $this->corps($client)['activites'][0]['taches'][0]['notes']);

            $this->connecter($client, $fixture['tiers']->getEmail());
            $client->request('POST', '/api/v1/initiative-notes', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'entiteType' => 'initiative_activite',
                'entiteUuid' => $fixture['activite']->getUuid()->toRfc4122(),
                'contenu' => 'Ne devrait pas passer.',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403, 'Un tiers sans initiatives.gerer ni responsabilité ne peut pas ajouter une observation.');
        } finally {
            $this->rollback();
        }
    }

    public function testTeleverserUnePieceJointeSurUneActivite(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['responsable']->getEmail());

            $cheminTemporaire = tempnam(sys_get_temp_dir(), 'sisep-test-');
            self::assertNotFalse($cheminTemporaire);
            file_put_contents($cheminTemporaire, 'contenu de test');
            $fichier = new UploadedFile($cheminTemporaire, 'note-cadrage.txt', 'text/plain', null, true);

            $client->request(
                'POST',
                '/api/v1/initiative-pieces-jointes',
                parameters: ['entiteType' => 'initiative_activite', 'entiteUuid' => $fixture['activite']->getUuid()->toRfc4122(), 'typeDocument' => 'note'],
                files: ['fichier' => $fichier],
            );
            self::assertResponseStatusCodeSame(201);
            $pieces = $this->corps($client)['activites'][0]['piecesJointes'];
            self::assertCount(1, $pieces);
            self::assertSame('note-cadrage.txt', $pieces[0]['nomFichier']);
        } finally {
            $this->rollback();
        }
    }

    public function testDateReellePoseeALaClotureEtEffaceeSiReouverte(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('PATCH', "/api/v1/taches-initiative/{$fixture['tache']->getUuid()->toRfc4122()}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'terminee' => true,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertNotNull($this->corps($client)['activites'][0]['dateReelle'], 'dateReelle (dérivée) doit être posée quand toutes les tâches sont terminées.');

            $client->request('PATCH', "/api/v1/taches-initiative/{$fixture['tache']->getUuid()->toRfc4122()}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'terminee' => false,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertNull($this->corps($client)['activites'][0]['dateReelle'], 'dateReelle doit être effacée dès qu\'une tâche ressort de "terminée".');
        } finally {
            $this->rollback();
        }
    }

    public function testTelechargementSelonVisibilite(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['responsable']->getEmail());
            $activiteUuid = $fixture['activite']->getUuid()->toRfc4122();

            $uuidTous = $this->televerserPieceJointe($client, $activiteUuid, 'tous');
            $uuidPhasaoc = $this->televerserPieceJointe($client, $activiteUuid, 'equipe_phasaoc');
            $uuidSectoriels = $this->televerserPieceJointe($client, $activiteUuid, 'sectoriels');
            $uuidAgent = $this->televerserPieceJointe($client, $activiteUuid, 'agents_specifiques', [$fixture['agentNomme']->getUuid()->toRfc4122()]);

            // Le créateur télécharge toujours, quelle que soit la visibilité.
            $this->assertTelechargement($client, $fixture['responsable']->getEmail(), $uuidSectoriels, 200);

            // « Tous » : accessible même à un tiers.
            $this->assertTelechargement($client, $fixture['tiers']->getEmail(), $uuidTous, 200);

            // « Équipe PHASAOC ».
            $this->assertTelechargement($client, $fixture['membrePhasaoc']->getEmail(), $uuidPhasaoc, 200);
            $this->assertTelechargement($client, $fixture['sectoriel']->getEmail(), $uuidPhasaoc, 403);
            $this->assertTelechargement($client, $fixture['tiers']->getEmail(), $uuidPhasaoc, 403);

            // « Sectoriels ».
            $this->assertTelechargement($client, $fixture['sectoriel']->getEmail(), $uuidSectoriels, 200);
            $this->assertTelechargement($client, $fixture['membrePhasaoc']->getEmail(), $uuidSectoriels, 403);

            // « Agents spécifiques ».
            $this->assertTelechargement($client, $fixture['agentNomme']->getEmail(), $uuidAgent, 200);
            $this->assertTelechargement($client, $fixture['tiers']->getEmail(), $uuidAgent, 403);

            // initiatives.gerer (coordination) passe toujours, même sur une pièce scopée « sectoriels ».
            $this->assertTelechargement($client, $fixture['coordonnateur']->getEmail(), $uuidSectoriels, 200);
        } finally {
            $this->rollback();
        }
    }

    public function testStatutEnRetardSurUneActiviteEtSaTache(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/initiative-activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiative' => $fixture['initiative']->getUuid()->toRfc4122(),
                'libelle' => 'Activité en retard',
                'dateDebut' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
                'echeance' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $activite = $this->trouverActiviteParLibelle($client, 'Activité en retard');
            self::assertSame('en_retard', $activite['statut'], 'Échéance dépassée et activité non terminée (aucune tâche) → en retard.');

            $client->request('POST', '/api/v1/taches-initiative', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiativeActivite' => $activite['uuid'],
                'libelle' => 'Tâche non terminée',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $activite = $this->trouverActiviteParLibelle($client, 'Activité en retard');

            self::assertSame('en_retard', $activite['taches'][0]['statut'], 'Une tâche emprunte les dates de son activité parente.');
        } finally {
            $this->rollback();
        }
    }

    public function testStatutARisqueQuandLaDureeEstBienEntameeEtLaProgressionFaible(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            // Durée totale 25 jours, 20 déjà écoulés (80 %, > 2/3 par défaut) — échéance pas encore dépassée.
            $client->request('POST', '/api/v1/initiative-activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiative' => $fixture['initiative']->getUuid()->toRfc4122(),
                'libelle' => 'Activité à risque',
                'dateDebut' => (new \DateTimeImmutable('-20 days'))->format('Y-m-d'),
                'echeance' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d'),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $activite = $this->trouverActiviteParLibelle($client, 'Activité à risque');

            $client->request('POST', '/api/v1/taches-initiative', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'initiativeActivite' => $activite['uuid'],
                'libelle' => 'Tâche non terminée',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $activite = $this->trouverActiviteParLibelle($client, 'Activité à risque');

            self::assertSame('a_risque', $activite['statut'], '80 % de la durée écoulée avec 0 % d\'avancement, seuils par défaut (2/3, écart -0,20) → à risque.');
        } finally {
            $this->rollback();
        }
    }

    /** @param list<string> $agentsUuid */
    private function televerserPieceJointe(KernelBrowser $client, string $activiteUuid, string $visibilite, array $agentsUuid = []): string
    {
        $cheminTemporaire = tempnam(sys_get_temp_dir(), 'sisep-test-');
        self::assertNotFalse($cheminTemporaire);
        file_put_contents($cheminTemporaire, 'contenu de test');
        $fichier = new UploadedFile($cheminTemporaire, 'document-'.$visibilite.'.txt', 'text/plain', null, true);

        $parametres = ['entiteType' => 'initiative_activite', 'entiteUuid' => $activiteUuid, 'visibilite' => $visibilite, 'typeDocument' => 'note'];
        if ([] !== $agentsUuid) {
            $parametres['agentsUuid'] = $agentsUuid;
        }

        $client->request('POST', '/api/v1/initiative-pieces-jointes', parameters: $parametres, files: ['fichier' => $fichier]);
        self::assertResponseStatusCodeSame(201);

        $pieces = $this->corps($client)['activites'][0]['piecesJointes'];

        return (string) end($pieces)['uuid'];
    }

    private function assertTelechargement(KernelBrowser $client, string $email, string $pieceUuid, int $statutAttendu): void
    {
        $this->connecter($client, $email);
        $client->request('GET', "/api/v1/pieces-jointes/{$pieceUuid}/telecharger");
        self::assertResponseStatusCodeSame($statutAttendu);
    }

    /** @return array<string, mixed> */
    private function trouverActiviteParLibelle(KernelBrowser $client, string $libelle): array
    {
        $activites = $this->corps($client)['activites'];

        return current(array_filter($activites, static fn ($a) => $libelle === $a['libelle']));
    }

    public function testSuppressionInitiativeDisparaitDeLaListe(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('DELETE', "/api/v1/initiatives/{$fixture['initiative']->getUuid()->toRfc4122()}");
            self::assertResponseStatusCodeSame(204);

            $client->request('GET', '/api/v1/initiatives');
            self::assertResponseIsSuccessful();
            $uuids = array_column($this->corps($client)['donnees'], 'uuid');
            self::assertNotContains($fixture['initiative']->getUuid()->toRfc4122(), $uuids);
        } finally {
            $this->rollback();
        }
    }

    /** @return array{0: KernelBrowser, 1: array<string, mixed>} */
    private function demarrer(): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $permInitiatives = new Permission('initiatives.gerer', 'initiatives', 'Gérer le suivi des projets');
        $this->em->persist($permInitiatives);

        $roleCoordonnateur = new Role('coordonnateur_initiative_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permInitiatives);
        $this->em->persist($roleCoordonnateur);

        $roleSansRien = new Role('lecteur_initiative_test', 'Lecteur', true);
        $this->em->persist($roleSansRien);

        $coordonnateur = new Utilisateur('coordonnateur.initiative.test@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $coordonnateur->marquerMdpChange();
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $responsable = new Utilisateur('responsable.initiative.test@example.test', 'temp', 'Sarr', 'Ibrahima');
        $responsable->setMotDePasseHash($hasher->hashPassword($responsable, self::MOT_DE_PASSE));
        $responsable->marquerMdpChange();
        $this->em->persist($responsable);
        $this->em->persist(new UtilisateurRole($responsable, $roleSansRien, null));

        $tiers = new Utilisateur('tiers.initiative.test@example.test', 'temp', 'Ba', 'Fatou');
        $tiers->setMotDePasseHash($hasher->hashPassword($tiers, self::MOT_DE_PASSE));
        $tiers->marquerMdpChange();
        $this->em->persist($tiers);
        $this->em->persist(new UtilisateurRole($tiers, $roleSansRien, null));

        // Codes de rôle réels (pas de suffixe _test) : PieceJointeAccesResolver
        // teste l'appartenance à équipe PHASAOC / sectoriels sur ces codes en
        // dur — la base de test n'est jamais semée (app:db:seed), donc pas de
        // collision d'unicité sur Role::$code.
        $roleMembrePhasaoc = new Role('responsable_suivi_evaluation', 'Responsable suivi-évaluation', true);
        $this->em->persist($roleMembrePhasaoc);
        $roleSectoriel = new Role('point_focal_structure', 'Point focal de structure', true);
        $this->em->persist($roleSectoriel);

        $membrePhasaoc = new Utilisateur('membre.phasaoc.initiative.test@example.test', 'temp', 'Diop', 'Cheikh');
        $membrePhasaoc->setMotDePasseHash($hasher->hashPassword($membrePhasaoc, self::MOT_DE_PASSE));
        $membrePhasaoc->marquerMdpChange();
        $this->em->persist($membrePhasaoc);
        $this->em->persist(new UtilisateurRole($membrePhasaoc, $roleMembrePhasaoc, null));

        $sectoriel = new Utilisateur('sectoriel.initiative.test@example.test', 'temp', 'Ndiaye', 'Awa');
        $sectoriel->setMotDePasseHash($hasher->hashPassword($sectoriel, self::MOT_DE_PASSE));
        $sectoriel->marquerMdpChange();
        $this->em->persist($sectoriel);
        $this->em->persist(new UtilisateurRole($sectoriel, $roleSectoriel, null));

        $agentNomme = new Utilisateur('agent.nomme.initiative.test@example.test', 'temp', 'Fall', 'Moussa');
        $agentNomme->setMotDePasseHash($hasher->hashPassword($agentNomme, self::MOT_DE_PASSE));
        $agentNomme->marquerMdpChange();
        $this->em->persist($agentNomme);
        $this->em->persist(new UtilisateurRole($agentNomme, $roleSansRien, null));

        // Responsable d'une tâche précise, mais pas de l'activité qui la porte — distinct de $responsable (§ correctif exigerResponsableOuGerer).
        $responsableTacheSeule = new Utilisateur('responsable.tache.seule.initiative.test@example.test', 'temp', 'Cissé', 'Mariama');
        $responsableTacheSeule->setMotDePasseHash($hasher->hashPassword($responsableTacheSeule, self::MOT_DE_PASSE));
        $responsableTacheSeule->marquerMdpChange();
        $this->em->persist($responsableTacheSeule);
        $this->em->persist(new UtilisateurRole($responsableTacheSeule, $roleSansRien, null));

        $initiative = new Initiative('RN/OCB', $coordonnateur);
        $this->em->persist($initiative);

        $activite = new InitiativeActivite($initiative, 'Phase pilote', 1);
        $activite->ajouterResponsable($responsable);
        $this->em->persist($activite);

        $tache = new TacheInitiative($activite, 'Cadrage', 1);
        $tache->setResponsable($responsable);
        $this->em->persist($tache);

        $this->em->flush();

        return [$client, [
            'coordonnateur' => $coordonnateur,
            'responsable' => $responsable,
            'responsableTacheSeule' => $responsableTacheSeule,
            'tiers' => $tiers,
            'membrePhasaoc' => $membrePhasaoc,
            'sectoriel' => $sectoriel,
            'agentNomme' => $agentNomme,
            'initiative' => $initiative,
            'activite' => $activite,
            'tache' => $tache,
        ]];
    }

    private function connecter(KernelBrowser $client, string $email): void
    {
        $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'motDePasse' => self::MOT_DE_PASSE,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function corps(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function rollback(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
    }
}
