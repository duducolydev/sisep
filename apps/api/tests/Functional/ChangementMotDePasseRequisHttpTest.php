<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Un mot de passe temporaire doit être changé avant tout usage. Le drapeau
 * `doitChangerMdp` existait sans que rien ne le fasse respecter : un compte
 * pouvait vivre indéfiniment avec un mot de passe que l'administrateur, et
 * parfois un terminal, avaient vu.
 */
final class ChangementMotDePasseRequisHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE_TEMPORAIRE = 'Temporaire123!';
    private const NOUVEAU_MOT_DE_PASSE = 'MonNouveauMotDePasse2026!';

    private EntityManagerInterface $em;

    public function testUnMotDePasseTemporaireNOuvreQueLeChangement(): void
    {
        $client = $this->demarrer();

        try {
            $this->connecter($client, self::MOT_DE_PASSE_TEMPORAIRE);

            // Le profil reste lisible : c'est par lui que l'interface sait
            // qu'il faut conduire l'utilisateur à l'écran de changement.
            $client->request('GET', '/api/v1/auth/moi');
            self::assertResponseIsSuccessful();
            self::assertTrue($this->corps($client)['doitChangerMdp']);

            $client->request('GET', '/api/v1/structures');
            self::assertResponseStatusCodeSame(403, 'Aucune donnée métier avant le changement du mot de passe temporaire.');
            self::assertSame('changement-mot-de-passe-requis', $this->corps($client)['type']);

            $client->request('POST', '/api/v1/auth/mot-de-passe', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'motDePasseActuel' => self::MOT_DE_PASSE_TEMPORAIRE,
                'nouveauMotDePasse' => self::NOUVEAU_MOT_DE_PASSE,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('GET', '/api/v1/structures');
            self::assertResponseIsSuccessful('Une fois le mot de passe changé, l\'application s\'ouvre.');
        } finally {
            $this->rollback();
        }
    }

    public function testLaDeconnexionResteOuverte(): void
    {
        $client = $this->demarrer();

        try {
            $this->connecter($client, self::MOT_DE_PASSE_TEMPORAIRE);
            $client->request('POST', '/api/v1/auth/deconnexion');
            self::assertNotSame(403, $client->getResponse()->getStatusCode(), 'On doit pouvoir sortir sans changer le mot de passe.');
        } finally {
            $this->rollback();
        }
    }

    private function demarrer(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $permission = new Permission('activites.consulter', 'activites', 'Consulter les activités');
        $this->em->persist($permission);
        $role = new Role('lecteur_mdp_test', 'Lecteur', true);
        $role->accorder($permission);
        $this->em->persist($role);

        // Compte tout juste créé : doitChangerMdp vaut vrai par défaut.
        $compte = new Utilisateur('nouveau.compte@example.test', 'temp', 'Compte', 'Nouveau');
        $compte->setMotDePasseHash($container->get(UserPasswordHasherInterface::class)->hashPassword($compte, self::MOT_DE_PASSE_TEMPORAIRE));
        $this->em->persist($compte);
        $this->em->persist(new UtilisateurRole($compte, $role, null));
        $this->em->flush();

        return $client;
    }

    private function connecter(KernelBrowser $client, string $motDePasse): void
    {
        $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'nouveau.compte@example.test',
            'motDePasse' => $motDePasse,
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
