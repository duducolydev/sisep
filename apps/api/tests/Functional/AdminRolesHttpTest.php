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

/** Onglet "Rôles et permissions" (§ 2.6, § 6.2) : PUT remplace intégralement l'ensemble des permissions d'un rôle. */
final class AdminRolesHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testPutRemplaceLensembleDesPermissionsDuRole(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $roleUuid = $fixture['roleLecteur']->getUuid()->toRfc4122();

            $client->request('PUT', "/api/v1/admin/roles/{$roleUuid}/permissions", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'permissions' => ['activites.consulter', 'exports.generer'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $apres = $this->corps($client);
            sort($apres['permissions']);
            self::assertSame(['activites.consulter', 'exports.generer'], $apres['permissions']);

            // Un deuxième PUT avec un sous-ensemble doit bien révoquer ce qui n'y figure plus.
            $client->request('PUT', "/api/v1/admin/roles/{$roleUuid}/permissions", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'permissions' => ['activites.consulter'],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertSame(['activites.consulter'], $this->corps($client)['permissions']);
        } finally {
            $this->rollback();
        }
    }

    public function testUnUtilisateurSansAdminRolesNePeutPasModifierLaMatrice(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['lecteur']->getEmail());

            $client->request('PUT', \sprintf('/api/v1/admin/roles/%s/permissions', $fixture['roleLecteur']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'permissions' => ['activites.consulter'],
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(403);
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

        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $permExports = new Permission('exports.generer', 'exports', 'Générer un export');
        $permAdminRoles = new Permission('admin.roles', 'admin', 'Gérer les rôles');
        $this->em->persist($permConsulter);
        $this->em->persist($permExports);
        $this->em->persist($permAdminRoles);

        $roleLecteur = new Role('lecteur_admin_test', 'Lecteur', true);
        $roleLecteur->accorder($permConsulter);
        $this->em->persist($roleLecteur);

        $roleCoordonnateur = new Role('coordonnateur_admin_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permAdminRoles);
        $this->em->persist($roleCoordonnateur);

        $structure = new Structure('TST-ADM', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $lecteur = new Utilisateur('lecteur.admin.test@example.test', 'temp', 'Diop', 'Cheikh');
        $lecteur->setMotDePasseHash($hasher->hashPassword($lecteur, self::MOT_DE_PASSE));
        $lecteur->marquerMdpChange();
        $this->em->persist($lecteur);
        $this->em->persist(new UtilisateurRole($lecteur, $roleLecteur, null));

        $coordonnateur = new Utilisateur('coordonnateur.admin.test@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $coordonnateur->marquerMdpChange();
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $this->em->flush();

        return [$client, [
            'lecteur' => $lecteur,
            'coordonnateur' => $coordonnateur,
            'roleLecteur' => $roleLecteur,
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
