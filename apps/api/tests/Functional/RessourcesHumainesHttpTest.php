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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Suivi des ressources humaines (§ 10, § 11 et § 14 du CR du 22/09/2026).
 * Les tests verrouillent les trois décisions de la réunion : suivi nominatif
 * du personnel technique, suivi agrégé des enquêteurs, et exclusion stricte
 * des données salariales.
 */
final class RessourcesHumainesHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLeSuiviRhEstReserveALUgp(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('GET', '/api/v1/rh/personnels');
            self::assertResponseStatusCodeSame(403, 'Les contrats ne regardent pas les structures bénéficiaires.');

            $client->request('POST', '/api/v1/rh/personnels', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'nom' => 'GUEYE',
                'prenom' => 'Mamadou',
                'sexe' => 'M',
                'fonction' => 'Chargé d\'étude junior',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    public function testCycleDeVieDUnAgentEtDeSesContrats(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/rh/personnels', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'nom' => 'GUEYE',
                'prenom' => 'Mamadou',
                'sexe' => 'M',
                'fonction' => 'Chargé d\'étude junior',
                'profil' => 'ISE',
                'specialite' => 'Statistiques économiques',
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            $personnelUuid = $corps['uuid'];
            self::assertSame('Mamadou GUEYE', $corps['nomComplet']);
            self::assertSame('ISE', $corps['profil']);
            self::assertNull($corps['contratCourant'], 'Un agent peut exister avant que son contrat ne soit saisi.');
            self::assertArrayNotHasKey('salaire', $corps, 'Aucune donnée salariale ne doit transiter (§ 14).');

            // Premier contrat.
            $client->request('POST', "/api/v1/rh/personnels/{$personnelUuid}/contrats", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'dateDebut' => '2026-01-01',
                'dateFin' => '2026-12-31',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $courant = $this->corps($client)['contratCourant'];
            self::assertSame('2026-12-31', $courant['dateFin']);
            self::assertSame(364, $courant['dureeEnJours']);
            self::assertSame(
                'Contrat de prestation',
                $courant['type'],
                "Tout le personnel technique du PHASAOC est sous contrat de prestation : le type n'a pas à être ressaisi.",
            );

            // Un renouvellement est un nouveau contrat, pas une prolongation.
            $client->request('POST', "/api/v1/rh/personnels/{$personnelUuid}/contrats", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'dateDebut' => '2027-01-01',
                'dateFin' => '2027-12-31',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            self::assertCount(2, $corps['contrats'], "Le contrat précédent reste dans l'historique.");
            self::assertSame('2027-01-01', $corps['contratCourant']['dateDebut'], 'Le contrat courant est le plus récent.');

            // Une échéance ne peut pas précéder le début.
            $client->request('POST', "/api/v1/rh/personnels/{$personnelUuid}/contrats", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'dateDebut' => '2028-06-01',
                'dateFin' => '2028-01-01',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    public function testLaPeriodeDeContratSeSaisitDepuisLaFicheDeLAgent(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            // À la création, la période ouvre le premier contrat : l'UGP n'a
            // pas à enchaîner sur un second écran (demande du 05/10/2026).
            $client->request('POST', '/api/v1/rh/personnels', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'nom' => 'SECK',
                'prenom' => 'Marie Agathe',
                'sexe' => 'F',
                'fonction' => 'Ingénieur Statisticien',
                'contrat' => ['dateDebut' => '2026-01-01', 'dateFin' => '2026-12-31'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            self::assertCount(1, $corps['contrats']);
            self::assertSame('2026-01-01', $corps['contratCourant']['dateDebut']);
            self::assertSame('2026-12-31', $corps['contratCourant']['dateFin']);
            self::assertSame('Contrat de prestation', $corps['contratCourant']['type'], "Le type par défaut s'applique sans être saisi.");
            $agentUuid = $corps['uuid'];

            // À la modification, elle corrige la période en cours — elle ne
            // crée pas une seconde ligne : renouveler reste un autre geste.
            $client->request('PATCH', "/api/v1/rh/personnels/{$agentUuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'contrat' => ['dateDebut' => '2026-02-01', 'dateFin' => '2026-11-30'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $corps = $this->corps($client);
            self::assertCount(1, $corps['contrats'], 'Corriger une période ne doit pas empiler les contrats.');
            self::assertSame('2026-02-01', $corps['contratCourant']['dateDebut']);
            self::assertSame('2026-11-30', $corps['contratCourant']['dateFin']);

            // L'ordre des bornes est vérifié côté serveur.
            $client->request('PATCH', "/api/v1/rh/personnels/{$agentUuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'contrat' => ['dateDebut' => '2026-06-01', 'dateFin' => '2026-03-01'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    public function testLaPeriodeDeMobilisationDesEnqueteurs(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/rh/effectifs-enqueteurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'annee' => 2026,
                'nombreFemmes' => 10,
                'nombreHommes' => 10,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            self::assertNull($corps['dateDebut'], "L'effectif se programme avant le calendrier de collecte.");
            self::assertNull($corps['dureeEnJours'], 'Pas de durée tant qu\'une borne manque — null, jamais 0.');
            $uuid = $corps['uuid'];

            $client->request('PATCH', "/api/v1/rh/effectifs-enqueteurs/{$uuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'dateDebut' => '2026-04-01',
                'dateFin' => '2026-04-30',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertSame(29, $this->corps($client)['dureeEnJours']);

            $client->request('PATCH', "/api/v1/rh/effectifs-enqueteurs/{$uuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'dateFin' => '2026-03-01',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'Une mobilisation ne peut pas se terminer avant de commencer.');
        } finally {
            $this->rollback();
        }
    }

    public function testLesEcheancesDeContratRemontentDansLeDelaiDemande(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $personnelUuid = $this->creerAgent($client, 'BA', 'Thimbane', 'F');

            $dansTrenteJours = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d');
            $dansDeuxAns = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 years')->format('Y-m-d');

            $client->request('POST', "/api/v1/rh/personnels/{$personnelUuid}/contrats", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'dateDebut' => '2026-01-01',
                'dateFin' => $dansTrenteJours,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $autreUuid = $this->creerAgent($client, 'NDAO', 'Diakhou', 'F');
            $client->request('POST', "/api/v1/rh/personnels/{$autreUuid}/contrats", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'dateDebut' => '2026-01-01',
                'dateFin' => $dansDeuxAns,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $client->request('GET', '/api/v1/rh/echeances?jours=90');
            self::assertResponseIsSuccessful();
            $corps = $this->corps($client);
            self::assertCount(1, $corps['donnees'], "Seule l'échéance des 90 prochains jours remonte.");
            self::assertSame('Thimbane BA', $corps['donnees'][0]['personnel']['nomComplet']);
            self::assertEqualsWithDelta(30, $corps['donnees'][0]['contrat']['joursAvantEcheance'], 1);
        } finally {
            $this->rollback();
        }
    }

    public function testEffectifEnqueteursAgregeParOperation(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/rh/effectifs-enqueteurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'libelle' => 'Collecte principale',
                'annee' => 2026,
                'nombreFemmes' => 180,
                'nombreHommes' => 220,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            self::assertSame(400, $corps['effectifTotal'], 'Le total est déduit, jamais saisi.');
            self::assertEqualsWithDelta(0.45, $corps['partFemmes'], 0.0001);

            // Les enquêteurs ne sont pas suivis nominativement.
            $client->request('GET', '/api/v1/rh/personnels');
            self::assertCount(0, $this->corps($client)['donnees']);
        } finally {
            $this->rollback();
        }
    }

    public function testUnEffectifVideNaPasDePartDeFemmes(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/rh/effectifs-enqueteurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'annee' => 2026,
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(201);
            self::assertNull($this->corps($client)['partFemmes'], 'Division par zéro → null, jamais 0 (§ 5.1).');
        } finally {
            $this->rollback();
        }
    }

    private function creerAgent(KernelBrowser $client, string $nom, string $prenom, string $sexe): string
    {
        $client->request('POST', '/api/v1/rh/personnels', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'nom' => $nom,
            'prenom' => $prenom,
            'sexe' => $sexe,
            'fonction' => 'Agent technique',
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
        $permRhConsulter = new Permission('rh.consulter', 'rh', 'Consulter le suivi des ressources humaines');
        $permRhGerer = new Permission('rh.gerer', 'rh', 'Gérer le personnel, les contrats et les effectifs');
        foreach ([$permConsulter, $permRhConsulter, $permRhGerer] as $p) {
            $this->em->persist($p);
        }

        $rolePointFocal = new Role('point_focal_rh_test', 'Point focal de structure', true);
        $rolePointFocal->accorder($permConsulter);
        $this->em->persist($rolePointFocal);

        $roleCoordonnateur = new Role('coordonnateur_rh_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permConsulter);
        $roleCoordonnateur->accorder($permRhConsulter);
        $roleCoordonnateur->accorder($permRhGerer);
        $this->em->persist($roleCoordonnateur);

        $dsid = new Structure('DSID-RH', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($dsid);

        $focalDsid = new Utilisateur('focal.dsid.rh@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focalDsid->setMotDePasseHash($hasher->hashPassword($focalDsid, self::MOT_DE_PASSE));
        $this->em->persist($focalDsid);
        $this->em->persist(new UtilisateurRole($focalDsid, $rolePointFocal, $dsid));

        $coordonnateur = new Utilisateur('coordination.rh@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $projet = new Projet('TESTRH', 'Projet RH', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, 'CRH', 'Composante RH');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CRH.1', 'Sous-composante RH');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CRH.1.1', 'EHCVM-3', $dsid);
        $this->em->persist($activite);

        $this->em->flush();

        return [$client, [
            'focalDsid' => $focalDsid,
            'coordonnateur' => $coordonnateur,
            'activite' => $activite,
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
