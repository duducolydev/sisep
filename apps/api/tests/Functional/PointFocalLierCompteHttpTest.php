<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Permission;
use App\Entity\PointFocal;
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
 * Rattachement d'un compte utilisateur existant à une fiche point focal via
 * PATCH /points-focaux/:uuid { utilisateurUuid }. Reste gardé par
 * points_focaux.gerer, scopée par structure (§ 7.2) — pas par
 * admin.utilisateurs : créer le compte et le lier sont deux actions
 * distinctes, avec des permissions distinctes.
 */
final class PointFocalLierCompteHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLierUnCompteUtilisateurExistantAuPointFocal(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/points-focaux/%s', $fixture['pointFocalDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'utilisateurUuid' => $fixture['compteALier']->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();
            $corps = $this->corps($client);
            self::assertSame($fixture['compteALier']->getUuid()->toRfc4122(), $corps['utilisateur']['uuid']);
            self::assertSame($fixture['compteALier']->getEmail(), $corps['utilisateur']['email']);
        } finally {
            $this->rollback();
        }
    }

    public function testUtilisateurUuidNullDelieLeCompte(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $fixture['pointFocalDsid']->setUtilisateur($fixture['compteALier']);
            $this->em->flush();

            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/points-focaux/%s', $fixture['pointFocalDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'utilisateurUuid' => null,
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();
            self::assertNull($this->corps($client)['utilisateur']);
        } finally {
            $this->rollback();
        }
    }

    public function testUtilisateurUuidInconnuEstRefuse(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/points-focaux/%s', $fixture['pointFocalDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'utilisateurUuid' => '00000000-0000-0000-0000-000000000000',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    public function testLierUnCompteResteGatePointsFocauxGererScopee(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/points-focaux/%s', $fixture['pointFocalDpee']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'utilisateurUuid' => $fixture['compteALier']->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(403, 'Un point focal DSID ne doit jamais lier un compte sur un point focal DPEE.');
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

        $permPointsFocaux = new Permission('points_focaux.gerer', 'points_focaux', 'Gérer les points focaux');
        $this->em->persist($permPointsFocaux);

        $rolePointFocal = new Role('point_focal_lier_compte_test', 'Point focal de structure', true);
        $rolePointFocal->accorder($permPointsFocaux);
        $this->em->persist($rolePointFocal);

        $dsid = new Structure('DSID-L', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $dpee = new Structure('DPEE-L', 'Direction de la prévision', TypeStructure::PARTENAIRE_NATIONAL);
        $this->em->persist($dsid);
        $this->em->persist($dpee);

        $focalDsid = new Utilisateur('focal.dsid.lier.test@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focalDsid->setMotDePasseHash($hasher->hashPassword($focalDsid, self::MOT_DE_PASSE));
        $this->em->persist($focalDsid);
        $this->em->persist(new UtilisateurRole($focalDsid, $rolePointFocal, $dsid));

        $compteALier = new Utilisateur('compte.a.lier.test@example.test', 'temp', 'Ba', 'Fatou');
        $compteALier->setMotDePasseHash($hasher->hashPassword($compteALier, self::MOT_DE_PASSE));
        $this->em->persist($compteALier);

        $pointFocalDsid = new PointFocal($dsid, 'Point focal DSID', 'Adjoint');
        $pointFocalDpee = new PointFocal($dpee, 'Point focal DPEE', 'Adjoint');
        $this->em->persist($pointFocalDsid);
        $this->em->persist($pointFocalDpee);

        $this->em->flush();

        return [$client, [
            'focalDsid' => $focalDsid,
            'compteALier' => $compteALier,
            'pointFocalDsid' => $pointFocalDsid,
            'pointFocalDpee' => $pointFocalDpee,
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
