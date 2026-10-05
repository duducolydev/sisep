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
 * Remontée automatique (§ 4.2, ActiviteStatutListener) : une activité passe
 * à "Terminée" dès que toutes ses sous-activités le sont, et en revient
 * automatiquement si l'une d'elles ne l'est plus — sans nouvelle validation,
 * simple remontée d'un fait déjà validé sous-activité par sous-activité.
 */
final class ActiviteStatutAutoHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLactiviteBasculeSeulementQuandToutesSesSousActivitesSontTerminees(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/sous-activites/%s', $fixture['sousActivite1']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'terminee',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('GET', \sprintf('/api/v1/activites/%s', $fixture['activite']->getUuid()->toRfc4122()));
            self::assertResponseIsSuccessful();
            self::assertSame('en_cours', $this->corps($client)['statut'], 'Une seule sous-activité terminée sur deux ne doit pas clôturer l\'activité.');

            $client->request('PATCH', \sprintf('/api/v1/sous-activites/%s', $fixture['sousActivite2']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'terminee',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('GET', \sprintf('/api/v1/activites/%s', $fixture['activite']->getUuid()->toRfc4122()));
            self::assertResponseIsSuccessful();
            self::assertSame('terminee', $this->corps($client)['statut'], 'Les deux sous-activités terminées doivent clôturer automatiquement l\'activité.');
        } finally {
            $this->rollback();
        }
    }

    public function testLactiviteRevientEnCoursSiUneSousActiviteNestPlusTerminee(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            foreach (['sousActivite1', 'sousActivite2'] as $cle) {
                $client->request('PATCH', \sprintf('/api/v1/sous-activites/%s', $fixture[$cle]->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                    'statut' => 'terminee',
                ], \JSON_THROW_ON_ERROR));
                self::assertResponseIsSuccessful();
            }

            $client->request('GET', \sprintf('/api/v1/activites/%s', $fixture['activite']->getUuid()->toRfc4122()));
            self::assertSame('terminee', $this->corps($client)['statut']);

            $client->request('PATCH', \sprintf('/api/v1/sous-activites/%s', $fixture['sousActivite1']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'en_cours',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('GET', \sprintf('/api/v1/activites/%s', $fixture['activite']->getUuid()->toRfc4122()));
            self::assertSame(
                'en_cours',
                $this->corps($client)['statut'],
                "L'activité ne doit plus afficher \"Terminée\" dès qu'une sous-activité ne l'est plus.",
            );
        } finally {
            $this->rollback();
        }
    }

    public function testLactiviteSuspendueNestJamaisEcraseeParLaRemonteeAutomatique(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/activites/%s', $fixture['activite']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'suspendue',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            foreach (['sousActivite1', 'sousActivite2'] as $cle) {
                $client->request('PATCH', \sprintf('/api/v1/sous-activites/%s', $fixture[$cle]->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                    'statut' => 'terminee',
                ], \JSON_THROW_ON_ERROR));
                self::assertResponseIsSuccessful();
            }

            $client->request('GET', \sprintf('/api/v1/activites/%s', $fixture['activite']->getUuid()->toRfc4122()));
            self::assertSame(
                'suspendue',
                $this->corps($client)['statut'],
                'Un statut administratif explicite (suspendue/annulee) ne doit jamais être écrasé par la remontée automatique.',
            );
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

        $permModifier = new Permission('activites.modifier', 'activites', 'Modifier une activité');
        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $permValider = new Permission('activites.valider', 'activites', "Valider la clôture d'une activité");
        foreach ([$permModifier, $permConsulter, $permValider] as $p) {
            $this->em->persist($p);
        }

        $roleCoordonnateur = new Role('coordonnateur_statut_auto_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permModifier);
        $roleCoordonnateur->accorder($permConsulter);
        $roleCoordonnateur->accorder($permValider);
        $this->em->persist($roleCoordonnateur);

        $structure = new Structure('TST-SA', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $coordonnateur = new Utilisateur('coordonnateur.statut.auto@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $projet = new Projet('TESTSTATUTAUTO', 'Projet statut auto', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, 'CSA', 'Composante statut auto');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CSA.1', 'Sous-composante statut auto');
        $this->em->persist($sousComposante);

        $activite = new Activite($sousComposante, 'CSA.1.1', 'Activité statut auto', $structure);
        $this->em->persist($activite);
        $sousActivite1 = new SousActivite($activite, 'Sous-activité 1');
        $this->em->persist($sousActivite1);
        $sousActivite2 = new SousActivite($activite, 'Sous-activité 2');
        $this->em->persist($sousActivite2);

        $this->em->flush();

        return [$client, [
            'coordonnateur' => $coordonnateur,
            'activite' => $activite,
            'sousActivite1' => $sousActivite1,
            'sousActivite2' => $sousActivite2,
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
