<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Suivi par étapes (§ 6 du CR du 22/09/2026). Deux règles structurent ces
 * tests : structurer relève de l'UGP (activites.creer), renseigner relève du
 * terrain (activites.modifier, portée-aware), et la progression d'une
 * activité se déduit de ses étapes sans jamais être stockée.
 */
final class EtapesActiviteHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLUgpDefinitUnGabaritQueLeTerrainNePeutPasCreer(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());
            $client->request('POST', '/api/v1/gabarits-etapes', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'libelle' => 'Gabarit interdit',
                'etapes' => ['Préparation'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403, 'Un point focal ne normalise pas la méthode de suivi du projet.');

            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $client->request('POST', '/api/v1/gabarits-etapes', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'libelle' => 'Opération de collecte',
                'etapes' => ['Préparation', 'Formation', 'Déploiement terrain'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            self::assertCount(3, $corps['etapes']);
            self::assertSame(1, $corps['etapes'][0]['ordre'], "L'ordre du tableau fait foi.");
            self::assertSame('Déploiement terrain', $corps['etapes'][2]['libelle']);
        } finally {
            $this->rollback();
        }
    }

    public function testInstanciationCopieLesEtapesEtRefuseUnSecondGabarit(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $gabaritUuid = $this->creerGabarit($client, 'Gabarit collecte', ['Préparation', 'Formation', 'Déploiement terrain']);
            $activiteUuid = $fixture['activiteDsid']->getUuid()->toRfc4122();

            $client->request('POST', "/api/v1/activites/{$activiteUuid}/etapes/instancier", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'gabarit' => $gabaritUuid,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            self::assertCount(3, $this->corps($client)['donnees']);

            // Réinstancier écraserait un suivi en cours : refusé.
            $client->request('POST', "/api/v1/activites/{$activiteUuid}/etapes/instancier", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'gabarit' => $gabaritUuid,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409);

            // Le gabarit est un référentiel : le renommer ne touche pas les
            // activités déjà structurées.
            $client->request('PATCH', "/api/v1/gabarits-etapes/{$gabaritUuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'etapes' => ['Autre découpage'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('GET', "/api/v1/activites/{$activiteUuid}/etapes");
            self::assertCount(3, $this->corps($client)['donnees'], 'Les étapes sont copiées, jamais référencées.');
        } finally {
            $this->rollback();
        }
    }

    public function testLeTerrainRenseigneEtLaProgressionSeDeduitDesEtapes(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $gabaritUuid = $this->creerGabarit($client, 'Gabarit progression', ['Préparation', 'Formation']);
            $activiteUuid = $fixture['activiteDsid']->getUuid()->toRfc4122();

            $client->request('POST', "/api/v1/activites/{$activiteUuid}/etapes/instancier", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'gabarit' => $gabaritUuid,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $etapes = $this->corps($client)['donnees'];

            // Structurée mais pas encore renseignée : 0, et non null.
            $client->request('GET', "/api/v1/activites/{$activiteUuid}");
            self::assertEqualsWithDelta(0.0, $this->corps($client)['progression'], 0.0001);
            self::assertSame(2, $this->corps($client)['etapesTotal']);

            // Le point focal de la DSID renseigne l'étape de sa structure.
            $this->connecter($client, $fixture['focalDsid']->getEmail());
            $client->request('PATCH', "/api/v1/etapes/{$etapes[0]['uuid']}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'avancement' => 100,
                'valeurActuelle' => '12',
                'observation' => 'Matériel livré',
                'dateMiseAJour' => '2026-10-05',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $corpsEtape = $this->corps($client);
            self::assertSame(100, $corpsEtape['avancement']);
            self::assertSame('2026-10-05', $corpsEtape['dateMiseAJour'], 'La date accompagne toujours le renseignement.');

            // Une étape sur deux à 100 % : la progression vaut la moyenne.
            $client->request('GET', "/api/v1/activites/{$activiteUuid}");
            $fiche = $this->corps($client);
            self::assertEqualsWithDelta(0.5, $fiche['progression'], 0.0001);
            self::assertSame(1, $fiche['etapesAchevees']);

            // Renseigner n'est pas structurer : le terrain ne retire pas une étape.
            $client->request('DELETE', "/api/v1/etapes/{$etapes[1]['uuid']}");
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    public function testUneActiviteSansEtapeNaPasDeProgression(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $client->request('GET', \sprintf('/api/v1/activites/%s', $fixture['activiteDsid']->getUuid()->toRfc4122()));

            self::assertResponseIsSuccessful();
            self::assertNull(
                $this->corps($client)['progression'],
                "Une activité non structurée n'est pas une activité à 0 % (§ 5.1).",
            );
        } finally {
            $this->rollback();
        }
    }

    public function testLivrableQuantifieEtPartageDesDroits(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $gabaritUuid = $this->creerGabarit($client, 'Gabarit livrables', ['Production']);
            $activiteUuid = $fixture['activiteDsid']->getUuid()->toRfc4122();

            $client->request('POST', "/api/v1/activites/{$activiteUuid}/etapes/instancier", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'gabarit' => $gabaritUuid,
            ], \JSON_THROW_ON_ERROR));
            $etapeUuid = $this->corps($client)['donnees'][0]['uuid'];

            $client->request('POST', "/api/v1/etapes/{$etapeUuid}/livrables", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'libelle' => 'Tablettes déployées',
                'unite' => 'tablettes',
                'quantiteAttendue' => '500',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $livrable = $this->corps($client)['livrables'][0];
            self::assertEqualsWithDelta(
                0.0,
                $livrable['tauxRealisation'],
                0.0001,
                'Rien de réalisé : 0, pas null — seule une quantité attendue nulle rend le taux indéfini.',
            );

            // Le terrain constate ce qui est réalisé.
            $this->connecter($client, $fixture['focalDsid']->getEmail());
            $client->request('PATCH', "/api/v1/livrables/{$livrable['uuid']}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'quantiteRealisee' => '125',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertEqualsWithDelta(0.25, $this->corps($client)['livrables'][0]['tauxRealisation'], 0.0001);

            // Mais il ne redéfinit pas ce qui était commandé.
            $client->request('PATCH', "/api/v1/livrables/{$livrable['uuid']}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'quantiteAttendue' => '125',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    public function testLeTerrainJointUnJustificatifAUneEtapeDeSaStructure(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $etapeDsid = $this->ajouterEtape($client, $fixture['activiteDsid']->getUuid()->toRfc4122(), 'Collecte');
            $etapeDpee = $this->ajouterEtape($client, $fixture['activiteDpee']->getUuid()->toRfc4122(), 'Collecte DPEE');

            $this->connecter($client, $fixture['focalDsid']->getEmail());
            $client->request('POST', "/api/v1/etapes/{$etapeDsid}/pieces-jointes", files: ['fichier' => $this->fichierTest()]);
            self::assertResponseStatusCodeSame(201, 'Le justificatif accompagne le renseignement : même droit que renseigner.');

            $piecesJointes = $this->corps($client)['piecesJointes'];
            self::assertCount(1, $piecesJointes);
            self::assertSame('pv-collecte.pdf', $piecesJointes[0]['nomFichier']);

            $client->request('GET', "/api/v1/pieces-jointes/{$piecesJointes[0]['uuid']}/telecharger");
            self::assertResponseIsSuccessful('La pièce jointe doit être téléchargeable par qui peut voir l\'activité.');

            // La portée s'applique au dépôt comme au renseignement.
            $client->request('POST', "/api/v1/etapes/{$etapeDpee}/pieces-jointes", files: ['fichier' => $this->fichierTest()]);
            self::assertResponseStatusCodeSame(403, 'Un point focal DSID ne dépose rien sur une étape de la DPEE.');
        } finally {
            $this->rollback();
        }
    }

    public function testLeTerrainNeDecoupePlusEnSousActivites(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());
            $client->request('POST', '/api/v1/sous-activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activiteDsid']->getUuid()->toRfc4122(),
                'libelle' => 'Découpage ad hoc',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403, 'Le découpage est réservé à l\'UGP (§ 5 du CR).');

            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $client->request('POST', '/api/v1/sous-activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activiteDsid']->getUuid()->toRfc4122(),
                'libelle' => 'Ligne budgétaire PTBA',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201, "L'UGP garde la main : la sous-activité reste la maille budgétaire.");
        } finally {
            $this->rollback();
        }
    }

    private function ajouterEtape(KernelBrowser $client, string $activiteUuid, string $libelle): string
    {
        $client->request('POST', "/api/v1/activites/{$activiteUuid}/etapes", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'libelle' => $libelle,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $this->corps($client)['uuid'];
    }

    private function fichierTest(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'sisep_etape_');
        self::assertNotFalse($chemin);
        file_put_contents($chemin, 'procès-verbal de collecte');

        return new UploadedFile($chemin, 'pv-collecte.pdf', 'application/pdf', null, true);
    }

    /** @param list<string> $etapes */
    private function creerGabarit(KernelBrowser $client, string $libelle, array $etapes): string
    {
        $client->request('POST', '/api/v1/gabarits-etapes', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'libelle' => $libelle,
            'etapes' => $etapes,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $this->corps($client)['uuid'];
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

        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $permModifier = new Permission('activites.modifier', 'activites', 'Modifier une activité');
        $permCreer = new Permission('activites.creer', 'activites', 'Créer une activité');
        $permParametres = new Permission('admin.parametres', 'admin', 'Gérer les paramètres');
        foreach ([$permConsulter, $permModifier, $permCreer, $permParametres] as $p) {
            $this->em->persist($p);
        }

        // Le point focal renseigne mais ne structure pas : ni activites.creer
        // ni admin.parametres.
        $rolePointFocal = new Role('point_focal_etapes_test', 'Point focal de structure', true);
        $rolePointFocal->accorder($permConsulter);
        $rolePointFocal->accorder($permModifier);
        $this->em->persist($rolePointFocal);

        $roleSuiviEvaluation = new Role('suivi_evaluation_etapes_test', 'Responsable suivi-évaluation', true);
        $roleSuiviEvaluation->accorder($permConsulter);
        $roleSuiviEvaluation->accorder($permModifier);
        $roleSuiviEvaluation->accorder($permCreer);
        $roleSuiviEvaluation->accorder($permParametres);
        $this->em->persist($roleSuiviEvaluation);

        $dsid = new Structure('DSID-ET', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($dsid);
        $dpee = new Structure('DPEE-ET', 'Direction de la prévision', TypeStructure::PARTENAIRE_NATIONAL);
        $this->em->persist($dpee);

        $focalDsid = new Utilisateur('focal.dsid.etapes@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focalDsid->setMotDePasseHash($hasher->hashPassword($focalDsid, self::MOT_DE_PASSE));
        $this->em->persist($focalDsid);
        $this->em->persist(new UtilisateurRole($focalDsid, $rolePointFocal, $dsid));

        $suiviEvaluation = new Utilisateur('suivi.etapes@example.test', 'temp', 'Ndiaye', 'Moussa');
        $suiviEvaluation->setMotDePasseHash($hasher->hashPassword($suiviEvaluation, self::MOT_DE_PASSE));
        $this->em->persist($suiviEvaluation);
        $this->em->persist(new UtilisateurRole($suiviEvaluation, $roleSuiviEvaluation, null));

        $projet = new Projet('TESTETAPES', 'Projet étapes', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, 'CET', 'Composante étapes');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CET.1', 'Sous-composante étapes');
        $this->em->persist($sousComposante);
        $activiteDsid = new Activite($sousComposante, 'CET.1.1', 'Activité DSID', $dsid);
        $this->em->persist($activiteDsid);
        $activiteDpee = new Activite($sousComposante, 'CET.1.2', 'Activité DPEE', $dpee);
        $this->em->persist($activiteDpee);

        $this->em->flush();

        return [$client, [
            'focalDsid' => $focalDsid,
            'suiviEvaluation' => $suiviEvaluation,
            'activiteDsid' => $activiteDsid,
            'activiteDpee' => $activiteDpee,
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

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }
}
