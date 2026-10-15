<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Exercice;
use App\Entity\Permission;
use App\Entity\PointFocal;
use App\Entity\Projet;
use App\Entity\RapportPeriodique;
use App\Entity\Role;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\PeriodeTrimestre;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Courriels applicatifs (§ décision SMTP) : ce qui compte ici n'est pas le
 * rendu du message mais qu'il parte au bon destinataire, et surtout qu'une
 * panne d'envoi ne remette jamais en cause l'écriture métier — d'où les
 * assertions sur le corps de réponse en plus du courriel lui-même.
 */
final class CourrielsHttpTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testCreationDeCompteEnvoieLeMotDePasseTemporaireAuTitulaire(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/admin/utilisateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'nouvelle.recrue@example.test',
                'nom' => 'Ba',
                'prenom' => 'Fatou',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            self::assertTrue($corps['courrielEnvoye']);

            self::assertEmailCount(1);
            $courriel = self::getMailerMessage();
            self::assertNotNull($courriel);
            self::assertEmailAddressContains($courriel, 'To', 'nouvelle.recrue@example.test');
            self::assertEmailHeaderSame($courriel, 'Subject', 'Votre compte SISEP a été créé');
            self::assertEmailHtmlBodyContains($courriel, $corps['motDePasseTemporaire']);
            self::assertEmailTextBodyContains($courriel, $corps['motDePasseTemporaire']);
        } finally {
            $this->rollback();
        }
    }

    public function testChangementDeMotDePasseEnvoieUneConfirmation(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/auth/mot-de-passe', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'motDePasseActuel' => self::MOT_DE_PASSE,
                'nouveauMotDePasse' => 'UnAutreMotDePasse456!',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            self::assertEmailCount(1);
            $courriel = self::getMailerMessage();
            self::assertNotNull($courriel);
            self::assertEmailAddressContains($courriel, 'To', $fixture['coordonnateur']->getEmail());
            self::assertEmailHeaderSame($courriel, 'Subject', 'Votre mot de passe SISEP a été modifié');
        } finally {
            $this->rollback();
        }
    }

    public function testRelanceEnvoieUnCourrielAuPointFocalDeLaStructure(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/rapports/relancer', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'structures' => [$fixture['structure']->getUuid()->toRfc4122()],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $corps = $this->corps($client);
            self::assertSame(1, $corps['relances']);
            self::assertSame(1, $corps['courriels']);
            self::assertSame(0, $corps['sansDestinataire']);

            self::assertEmailCount(1);
            $courriel = self::getMailerMessage();
            self::assertNotNull($courriel);
            self::assertEmailAddressContains($courriel, 'To', 'focal.relance@example.test');
            self::assertEmailHtmlBodyContains($courriel, 'DSID-C');
        } finally {
            $this->rollback();
        }
    }

    public function testRelanceResteEnregistreeSansAdresseDeDestination(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            // Le point focal perd son adresse : la relance doit rester
            // horodatée (action de pilotage tracée) sans courriel parti.
            $fixture['pointFocal']->setEmail(null);
            $this->em->flush();

            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/rapports/relancer', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'structures' => [$fixture['structure']->getUuid()->toRfc4122()],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $corps = $this->corps($client);
            self::assertSame(1, $corps['relances'], 'La relance est enregistrée même sans destinataire.');
            self::assertSame(0, $corps['courriels']);
            self::assertSame(1, $corps['sansDestinataire']);
            self::assertEmailCount(0);
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

        $permAdminUtilisateurs = new Permission('admin.utilisateurs', 'admin', 'Gérer les comptes utilisateurs');
        $permPointsFocaux = new Permission('points_focaux.gerer', 'points_focaux', 'Gérer les points focaux');
        $this->em->persist($permAdminUtilisateurs);
        $this->em->persist($permPointsFocaux);

        $roleCoordonnateur = new Role('coordonnateur_courriels_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permAdminUtilisateurs);
        $roleCoordonnateur->accorder($permPointsFocaux);
        $this->em->persist($roleCoordonnateur);

        $coordonnateur = new Utilisateur('coordination.courriels@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $coordonnateur->marquerMdpChange();
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $structure = new Structure('DSID-C', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $pointFocal = new PointFocal($structure, 'Ibrahima Sarr', 'Point focal');
        $pointFocal->setEmail('focal.relance@example.test');
        $this->em->persist($pointFocal);

        $projet = new Projet('TESTMAIL', 'Projet test courriels', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);

        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);

        // Échéance dans le passé : le rapport est "en retard" au sens de
        // estEnRetard(), seul cas que relancer() traite.
        $rapport = new RapportPeriodique($structure, $exercice, PeriodeTrimestre::T1, new \DateTimeImmutable('2026-01-15'));
        $this->em->persist($rapport);

        $this->em->flush();

        return [$client, [
            'coordonnateur' => $coordonnateur,
            'structure' => $structure,
            'pointFocal' => $pointFocal,
            'rapport' => $rapport,
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
