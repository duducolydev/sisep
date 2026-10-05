<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * /admin/utilisateurs (§ 4.5, § 7) : admin.utilisateurs n'existe qu'en
 * portée globale, seul le coordonnateur la détient. Le mot de passe initial
 * est généré côté serveur et n'apparaît qu'une seule fois, dans la réponse
 * de création (aucun mailer configuré).
 */
final class AdminUtilisateursHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testCreationRetourneUnMotDePasseTemporaireUneSeuleFois(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/admin/utilisateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'nouveau.focal@example.test',
                'nom' => 'Ba',
                'prenom' => 'Fatou',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            self::assertArrayHasKey('motDePasseTemporaire', $corps);
            self::assertNotSame('', $corps['motDePasseTemporaire']);
            self::assertTrue($corps['doitChangerMdp']);

            $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'nouveau.focal@example.test',
                'motDePasse' => $corps['motDePasseTemporaire'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful('Le compte créé doit pouvoir se connecter avec le mot de passe temporaire retourné.');
        } finally {
            $this->rollback();
        }
    }

    public function testCreationRefuseUnEmailDejaUtilise(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/admin/utilisateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => $fixture['coordonnateur']->getEmail(),
                'nom' => 'Doublon',
                'prenom' => 'Test',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(422);
            $corps = $this->corps($client);
            self::assertSame('email', $corps['violations'][0]['champ']);
        } finally {
            $this->rollback();
        }
    }

    public function testCreationAvecRolesInitiaux(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/admin/utilisateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'focal.avec.role@example.test',
                'nom' => 'Sarr',
                'prenom' => 'Ibrahima',
                'structure' => $fixture['dsid']->getUuid()->toRfc4122(),
                'roles' => [
                    ['role' => $fixture['rolePointFocal']->getCode(), 'structure' => $fixture['dsid']->getUuid()->toRfc4122()],
                ],
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(201);
            $corps = $this->corps($client);
            self::assertCount(1, $corps['roles']);
            self::assertSame($fixture['rolePointFocal']->getCode(), $corps['roles'][0]['role']);
            self::assertSame($fixture['dsid']->getUuid()->toRfc4122(), $corps['roles'][0]['structure']);
        } finally {
            $this->rollback();
        }
    }

    public function testCreationRefuseUnRoleInconnu(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/admin/utilisateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'focal.role.inconnu@example.test',
                'nom' => 'Test',
                'prenom' => 'Test',
                'roles' => [['role' => 'role_qui_nexiste_pas']],
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    public function testListeEtEcritureExigentAdminUtilisateurs(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('GET', '/api/v1/admin/utilisateurs');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/api/v1/admin/utilisateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'intrus@example.test',
                'nom' => 'Intrus',
                'prenom' => 'Test',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);

            $client->request('PATCH', \sprintf('/api/v1/admin/utilisateurs/%s', $fixture['focalDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'actif' => false,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    public function testModificationChangeStructureEtActif(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/admin/utilisateurs/%s', $fixture['focalDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'actif' => false,
                'fonction' => 'Ancien point focal',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();
            $corps = $this->corps($client);
            self::assertFalse($corps['actif']);
            self::assertSame('Ancien point focal', $corps['fonction']);
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

        $roleCoordonnateur = new Role('coordonnateur_utilisateurs_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permAdminUtilisateurs);
        $this->em->persist($roleCoordonnateur);

        $rolePointFocal = new Role('point_focal_utilisateurs_test', 'Point focal de structure', true);
        $rolePointFocal->accorder($permPointsFocaux);
        $this->em->persist($rolePointFocal);

        $dsid = new Structure('DSID-U', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($dsid);

        $coordonnateur = new Utilisateur('coordonnateur.utilisateurs.test@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $focalDsid = new Utilisateur('focal.dsid.utilisateurs.test@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focalDsid->setMotDePasseHash($hasher->hashPassword($focalDsid, self::MOT_DE_PASSE));
        $focalDsid->setStructure($dsid);
        $this->em->persist($focalDsid);
        $this->em->persist(new UtilisateurRole($focalDsid, $rolePointFocal, $dsid));

        $this->em->flush();

        return [$client, [
            'coordonnateur' => $coordonnateur,
            'focalDsid' => $focalDsid,
            'dsid' => $dsid,
            'rolePointFocal' => $rolePointFocal,
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
