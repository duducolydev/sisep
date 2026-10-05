<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousActivite;
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
 * Clôturer une activité ou une sous-activité (statut = terminee) est une
 * validation distincte de sa modification courante (§ 7.2, même séparation
 * que budget.*.valider/indicateurs.valider) : le point focal qui saisit
 * l'avancement à 100 % ne peut pas se l'auto-valider, seule
 * activites.valider (coordonnateur) le permet.
 */
final class ClotureActiviteHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testUnPointFocalNePeutPasCloturerSaPropreSousActivite(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/sous-activites/%s', $fixture['sousActiviteDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'terminee',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(403, 'activites.modifier seul ne doit jamais suffire à clôturer, même sur sa propre sous-activité.');
        } finally {
            $this->rollback();
        }
    }

    public function testUnPointFocalPeutToujoursChangerLesAutresStatuts(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/sous-activites/%s', $fixture['sousActiviteDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'en_cours',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful('activites.modifier doit rester suffisant pour les statuts qui ne clôturent pas.');
            self::assertSame('en_cours', $this->corps($client)['statut']);
        } finally {
            $this->rollback();
        }
    }

    public function testLeCoordonnateurPeutCloturerLaSousActivite(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/sous-activites/%s', $fixture['sousActiviteDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'terminee',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();
            self::assertSame('terminee', $this->corps($client)['statut']);
        } finally {
            $this->rollback();
        }
    }

    public function testLeCoordonnateurPeutCloturerLactivite(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/activites/%s', $fixture['activiteDsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'terminee',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();
            self::assertSame('terminee', $this->corps($client)['statut']);
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

        $permActivitesModifier = new Permission('activites.modifier', 'activites', 'Modifier une activité');
        $permActivitesConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $permActivitesValider = new Permission('activites.valider', 'activites', "Valider la clôture d'une activité");
        foreach ([$permActivitesModifier, $permActivitesConsulter, $permActivitesValider] as $p) {
            $this->em->persist($p);
        }

        $rolePointFocal = new Role('point_focal_cloture_test', 'Point focal de structure', true);
        $rolePointFocal->accorder($permActivitesModifier);
        $rolePointFocal->accorder($permActivitesConsulter);
        $this->em->persist($rolePointFocal);

        $roleCoordonnateur = new Role('coordonnateur_cloture_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permActivitesModifier);
        $roleCoordonnateur->accorder($permActivitesConsulter);
        $roleCoordonnateur->accorder($permActivitesValider);
        $this->em->persist($roleCoordonnateur);

        $dsid = new Structure('DSID-CL', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($dsid);

        $focalDsid = new Utilisateur('focal.dsid.cloture@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focalDsid->setMotDePasseHash($hasher->hashPassword($focalDsid, self::MOT_DE_PASSE));
        $this->em->persist($focalDsid);
        $this->em->persist(new UtilisateurRole($focalDsid, $rolePointFocal, $dsid));

        $coordonnateur = new Utilisateur('coordonnateur.cloture@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $projet = new Projet('TESTCLOTURE', 'Projet clôture', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, 'CCL', 'Composante clôture');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CCL.1', 'Sous-composante clôture');
        $this->em->persist($sousComposante);

        $activiteDsid = new Activite($sousComposante, 'CCL.1.1', 'Activité DSID', $dsid);
        $this->em->persist($activiteDsid);
        $sousActiviteDsid = new SousActivite($activiteDsid, 'Sous-activité DSID');
        $this->em->persist($sousActiviteDsid);

        $this->em->flush();

        return [$client, [
            'focalDsid' => $focalDsid,
            'coordonnateur' => $coordonnateur,
            'activiteDsid' => $activiteDsid,
            'sousActiviteDsid' => $sousActiviteDsid,
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
