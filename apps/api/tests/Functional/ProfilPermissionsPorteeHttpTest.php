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
 * GET /auth/moi expose désormais, en plus de la liste à plat "permissions",
 * la portée de chaque code ("permissionsPortee") : null = global, sinon la
 * liste des UUID de structures autorisées. Reste un hint d'affichage pour le
 * front (§ 8) — jamais une source d'autorisation, le Voter reste seul juge.
 */
final class ProfilPermissionsPorteeHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testUneAttributionGlobaleExposeNullCommePortee(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('GET', '/api/v1/auth/moi');
            self::assertResponseIsSuccessful();

            $corps = $this->corps($client);
            self::assertArrayHasKey('activites.modifier', $corps['permissionsPortee']);
            self::assertNull($corps['permissionsPortee']['activites.modifier']);
        } finally {
            $this->rollback();
        }
    }

    public function testUneAttributionScopeeExposeLaListeDesStructures(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());
            $client->request('GET', '/api/v1/auth/moi');
            self::assertResponseIsSuccessful();

            $corps = $this->corps($client);
            self::assertSame(
                [$fixture['dsid']->getUuid()->toRfc4122()],
                $corps['permissionsPortee']['activites.modifier'],
            );
        } finally {
            $this->rollback();
        }
    }

    public function testUnePermissionNonDetenueEstAbsenteDeLaPortee(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());
            $client->request('GET', '/api/v1/auth/moi');
            self::assertResponseIsSuccessful();

            $corps = $this->corps($client);
            self::assertArrayNotHasKey('admin.roles', $corps['permissionsPortee']);
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

        $permActivites = new Permission('activites.modifier', 'activites', 'Modifier une activité');
        $this->em->persist($permActivites);

        $roleCoordonnateur = new Role('coordonnateur_portee_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permActivites);
        $this->em->persist($roleCoordonnateur);

        $rolePointFocal = new Role('point_focal_portee_test', 'Point focal de structure', true);
        $rolePointFocal->accorder($permActivites);
        $this->em->persist($rolePointFocal);

        $dsid = new Structure('DSID-P', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($dsid);

        $coordonnateur = new Utilisateur('coordonnateur.portee.test@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $coordonnateur->marquerMdpChange();
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $focalDsid = new Utilisateur('focal.dsid.portee.test@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focalDsid->setMotDePasseHash($hasher->hashPassword($focalDsid, self::MOT_DE_PASSE));
        $focalDsid->marquerMdpChange();
        $this->em->persist($focalDsid);
        $this->em->persist(new UtilisateurRole($focalDsid, $rolePointFocal, $dsid));

        $this->em->flush();

        return [$client, [
            'coordonnateur' => $coordonnateur,
            'focalDsid' => $focalDsid,
            'dsid' => $dsid,
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
