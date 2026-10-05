<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Exercice;
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
 * Critère d'acceptation P3 (§ 10 du brief) : les tentatives d'engagement
 * au-delà de la dotation et de dépense au-delà de l'engagement sont
 * refusées côté API, avec un message explicite. Couvre aussi la séparation
 * stricte saisie/validation (§ 7.2) et les grandeurs dérivées (§ 5.1).
 */
final class BudgetChaineHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testEngagementRefuseAuDelaDeLaDotation(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $client->request('POST', '/api/v1/dotations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => $fixture['exercice']->getAnnee(),
                'montant' => 1_000_000,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $client->request('POST', '/api/v1/engagements', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => $fixture['exercice']->getAnnee(),
                'nature' => 'fonctionnement',
                'montant' => 1_500_000,
                'dateEngagement' => '2026-03-01',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $engagementUuid = $this->corps($client)['uuid'];

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/engagements/{$engagementUuid}/valider");

            self::assertResponseStatusCodeSame(422);
            $probleme = $this->corps($client);
            self::assertSame('Contrainte budgétaire violée', $probleme['title']);
            self::assertStringContainsString('dotation', $probleme['detail']);

            // Refusé en base, pas seulement en apparence.
            $statutEnBase = $this->em->getConnection()->fetchOne('SELECT statut FROM engagements WHERE uuid = ?', [$engagementUuid]);
            self::assertSame('brouillon', $statutEnBase);
        } finally {
            $this->rollback();
        }
    }

    public function testDepenseRefuseeAuDelaDeLEngagement(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $client->request('POST', '/api/v1/dotations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => $fixture['exercice']->getAnnee(),
                'montant' => 1_000_000,
            ], \JSON_THROW_ON_ERROR));

            $client->request('POST', '/api/v1/engagements', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => $fixture['exercice']->getAnnee(),
                'nature' => 'fonctionnement',
                'montant' => 500_000,
                'dateEngagement' => '2026-03-01',
            ], \JSON_THROW_ON_ERROR));
            $engagementUuid = $this->corps($client)['uuid'];

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/engagements/{$engagementUuid}/valider");
            self::assertResponseIsSuccessful();

            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $client->request('POST', '/api/v1/depenses', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'engagement' => $engagementUuid,
                'montant' => 600_000,
                'dateDepense' => '2026-06-01',
            ], \JSON_THROW_ON_ERROR));
            $depenseUuid = $this->corps($client)['uuid'];

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/depenses/{$depenseUuid}/valider");

            self::assertResponseStatusCodeSame(422);
            $probleme = $this->corps($client);
            self::assertSame('Contrainte budgétaire violée', $probleme['title']);
            self::assertStringContainsString('engagé', $probleme['detail']);
        } finally {
            $this->rollback();
        }
    }

    public function testLeCreateurNePeutPasValiderSonPropreEngagement(): void
    {
        // Le gestionnaire cumule ici saisir ET valider — exactement le cas
        // que cette règle doit couvrir en plus de la séparation des rôles
        // (§ 7.2) : même détentrice des deux permissions, elle ne doit pas
        // pouvoir valider ce qu'elle a saisi elle-même.
        [$client, $fixture] = $this->demarrer(cumulerPermissionsGestionnaire: true);

        try {
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $client->request('POST', '/api/v1/dotations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => $fixture['exercice']->getAnnee(),
                'montant' => 1_000_000,
            ], \JSON_THROW_ON_ERROR));

            $client->request('POST', '/api/v1/engagements', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => $fixture['exercice']->getAnnee(),
                'nature' => 'fonctionnement',
                'montant' => 200_000,
                'dateEngagement' => '2026-03-01',
            ], \JSON_THROW_ON_ERROR));
            $engagementUuid = $this->corps($client)['uuid'];

            // Le gestionnaire qui a saisi tente de valider lui-même — refusé
            // même s'il détenait par ailleurs budget.engagement.valider.
            $client->request('POST', "/api/v1/engagements/{$engagementUuid}/valider");

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('saisi', $this->corps($client)['detail']);
        } finally {
            $this->rollback();
        }
    }

    public function testGrandeursDeriveesNullSansDotation(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('GET', \sprintf(
                '/api/v1/budget/execution?niveau=activite&exercice=%d',
                $fixture['exercice']->getAnnee(),
            ));
            self::assertResponseIsSuccessful();

            $lignes = $this->corps($client)['donnees'];
            $ligne = current(array_filter($lignes, fn (array $l) => $l['uuid'] === $fixture['activite']->getUuid()->toRfc4122()));

            self::assertNotFalse($ligne, "L'activité de test doit apparaître dans /budget/execution.");
            self::assertNull($ligne['notifie'], 'Sans dotation, notifié doit être null, pas 0.');
            self::assertNull($ligne['disponible']);
            self::assertNull($ligne['tauxEngagement']);
            self::assertNull($ligne['tauxExecution']);
        } finally {
            $this->rollback();
        }
    }

    /** @return array{0: KernelBrowser, 1: array<string, mixed>} */
    private function demarrer(bool $cumulerPermissionsGestionnaire = false): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $permSaisirEng = new Permission('budget.engagement.saisir', 'budget', 'Saisir un engagement');
        $permValiderEng = new Permission('budget.engagement.valider', 'budget', 'Valider un engagement');
        $permSaisirDep = new Permission('budget.depense.saisir', 'budget', 'Saisir une dépense');
        $permValiderDep = new Permission('budget.depense.valider', 'budget', 'Valider une dépense');
        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        foreach ([$permSaisirEng, $permValiderEng, $permSaisirDep, $permValiderDep, $permConsulter] as $p) {
            $this->em->persist($p);
        }

        $roleGestionnaire = new Role('gestionnaire_budgetaire', 'Gestionnaire budgétaire', true);
        $roleGestionnaire->accorder($permSaisirEng);
        $roleGestionnaire->accorder($permSaisirDep);
        $roleGestionnaire->accorder($permConsulter);
        if ($cumulerPermissionsGestionnaire) {
            $roleGestionnaire->accorder($permValiderEng);
            $roleGestionnaire->accorder($permValiderDep);
        }

        $roleCoordonnateur = new Role('coordonnateur', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permValiderEng);
        $roleCoordonnateur->accorder($permValiderDep);
        $roleCoordonnateur->accorder($permConsulter);

        $this->em->persist($roleGestionnaire);
        $this->em->persist($roleCoordonnateur);

        $structure = new Structure('TST-P3', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $gestionnaire = new Utilisateur('gestionnaire.p3@example.test', 'temp', 'Fall', 'Khadija');
        $gestionnaire->setMotDePasseHash($hasher->hashPassword($gestionnaire, self::MOT_DE_PASSE));
        $this->em->persist($gestionnaire);
        $this->em->persist(new UtilisateurRole($gestionnaire, $roleGestionnaire, null));

        $coordonnateur = new Utilisateur('coordonnateur.p3@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $projet = new Projet('TESTP3', 'Projet test P3', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);
        $composante = new Composante($projet, 'CP3', 'Composante test P3');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CP3.1', 'Sous-composante test P3');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CP3.1.1', 'Activité test P3', $structure);
        $this->em->persist($activite);

        $this->em->flush();

        return [$client, [
            'gestionnaire' => $gestionnaire,
            'coordonnateur' => $coordonnateur,
            'activite' => $activite,
            'exercice' => $exercice,
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

    public function testEngagementValideDansLaLimiteFonctionne(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $client->request('POST', '/api/v1/dotations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => $fixture['exercice']->getAnnee(),
                'montant' => 1_000_000,
            ], \JSON_THROW_ON_ERROR));

            $client->request('POST', '/api/v1/engagements', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => $fixture['exercice']->getAnnee(),
                'nature' => 'fonctionnement',
                'montant' => 800_000,
                'dateEngagement' => '2026-03-01',
            ], \JSON_THROW_ON_ERROR));
            $engagementUuid = $this->corps($client)['uuid'];

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/engagements/{$engagementUuid}/valider");

            self::assertResponseIsSuccessful();
            self::assertSame('valide', $this->corps($client)['statut']);
        } finally {
            $this->rollback();
        }
    }
}
