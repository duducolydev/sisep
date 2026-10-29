<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JetonReinitialisation;
use App\Entity\JournalActivite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Mot de passe oublié » (29/10/2026) : le titulaire d'un compte reçoit un
 * lien par courriel et choisit lui-même un nouveau mot de passe, sans passer
 * par l'administrateur.
 */
final class MotDePasseOublieHttpTest extends WebTestCase
{
    private const ANCIEN = 'AncienMotDePasse2026!';
    private const NOUVEAU = 'NouveauMotDePasse2026!';
    private const SUJET_LIEN = 'Choisir un nouveau mot de passe SISEP';

    private EntityManagerInterface $em;

    public function testLeLienPermetDeChoisirUnNouveauMotDePasseUneSeuleFois(): void
    {
        [$client, $compte] = $this->demarrer();

        try {
            // Compte verrouillé par des échecs qu'un tiers aurait provoqués.
            for ($i = 0; $i < 8; ++$i) {
                $compte->enregistrerEchecConnexion(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
            $this->em->flush();

            // La casse de l'adresse saisie ne compte pas.
            $this->demander($client, 'Agent.Oubli@Example.test');
            self::assertResponseStatusCodeSame(202);
            $jeton = $this->jetonDuDernierLien('agent.oubli@example.test');

            $this->reinitialiser($client, $jeton, self::NOUVEAU);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $this->courriels('Votre mot de passe SISEP a été modifié'), 'Le titulaire est prévenu du changement.');

            $this->connecter($client, self::ANCIEN);
            self::assertResponseStatusCodeSame(401, "L'ancien mot de passe ne fonctionne plus.");
            $this->connecter($client, self::NOUVEAU);
            self::assertResponseIsSuccessful('Nouveau mot de passe accepté, verrouillage levé.');
            $client->request('GET', '/api/v1/auth/moi');
            self::assertFalse($this->corps($client)['doitChangerMdp'], 'Choisi par le titulaire : rien à changer ensuite.');

            $this->reinitialiser($client, $jeton, 'EncoreUnAutreMotDePasse!');
            self::assertResponseStatusCodeSame(422, 'Un lien ne sert qu\'une fois.');
            self::assertSame('Lien invalide ou expiré', $this->corps($client)['title']);

            $changements = array_filter(
                $this->em->getRepository(JournalActivite::class)->findBy(['entiteType' => 'utilisateur', 'action' => 'modification']),
                static fn (JournalActivite $j) => \array_key_exists('motDePasseHash', $j->getApres() ?? []),
            );
            self::assertCount(1, $changements, 'Le changement est au journal…');
            self::assertSame('(masqué)', array_values($changements)[0]->getApres()['motDePasseHash'], '… sans l\'empreinte.');
        } finally {
            $this->rollback();
        }
    }

    public function testLaReponseNeDitPasSiUnCompteCorrespond(): void
    {
        [$client, $compte] = $this->demarrer();

        try {
            $this->demander($client, 'personne@example.test');
            self::assertResponseStatusCodeSame(202);
            $reponseInconnu = $client->getResponse()->getContent();

            // Un compte désactivé ou supprimé ne se rouvre pas par ce chemin.
            $compte->setActif(false);
            $this->em->flush();
            $this->demander($client, 'agent.oubli@example.test');
            self::assertResponseStatusCodeSame(202);
            self::assertSame($reponseInconnu, $client->getResponse()->getContent(), 'Même réponse, compte ou pas.');

            $compte->setActif(true);
            $compte->supprimer();
            $this->em->flush();
            $this->demander($client, 'agent.oubli@example.test');
            self::assertResponseStatusCodeSame(202);

            self::assertCount(0, $this->courriels(self::SUJET_LIEN));
        } finally {
            $this->rollback();
        }
    }

    public function testUneRafaleDeDemandesNEnvoieQuUnCourriel(): void
    {
        [$client] = $this->demarrer();

        try {
            // Les courriels se comptent requête par requête.
            $this->demander($client, 'agent.oubli@example.test');
            self::assertCount(1, $this->courriels(self::SUJET_LIEN));

            for ($i = 0; $i < 2; ++$i) {
                $this->demander($client, 'agent.oubli@example.test');
                self::assertResponseStatusCodeSame(202, 'Même réponse, pour ne rien laisser deviner.');
                self::assertCount(0, $this->courriels(self::SUJET_LIEN));
            }
        } finally {
            $this->rollback();
        }
    }

    public function testUneNouvelleDemandeAnnuleLeLienPrecedent(): void
    {
        [$client, $compte] = $this->demarrer();

        try {
            $ilYaDixMinutes = new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC'));
            $this->em->persist(new JetonReinitialisation($compte, 'lien-precedent', $ilYaDixMinutes, 60));
            $this->em->flush();

            $this->demander($client, 'agent.oubli@example.test');
            self::assertCount(1, $this->courriels(self::SUJET_LIEN));

            $this->reinitialiser($client, 'lien-precedent', self::NOUVEAU);
            self::assertResponseStatusCodeSame(422, 'Remplacé par la demande plus récente.');
        } finally {
            $this->rollback();
        }
    }

    public function testUnLienPerimeEstRefuse(): void
    {
        [$client, $compte] = $this->demarrer();

        try {
            $ilYaDeuxHeures = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
            $this->em->persist(new JetonReinitialisation($compte, 'lien-perime', $ilYaDeuxHeures, 60));
            $this->em->flush();

            $this->reinitialiser($client, 'lien-perime', self::NOUVEAU);
            self::assertResponseStatusCodeSame(422);

            $this->connecter($client, self::ANCIEN);
            self::assertResponseIsSuccessful('Le mot de passe est resté le même.');
        } finally {
            $this->rollback();
        }
    }

    public function testLeNouveauMotDePasseSuitLesMemesExigences(): void
    {
        [$client] = $this->demarrer();

        try {
            $this->demander($client, 'agent.oubli@example.test');
            $jeton = $this->jetonDuDernierLien('agent.oubli@example.test');

            $this->reinitialiser($client, $jeton, 'court');
            self::assertResponseStatusCodeSame(422);
            self::assertSame('nouveauMotDePasse', $this->corps($client)['violations'][0]['champ']);

            $this->reinitialiser($client, $jeton, self::NOUVEAU);
            self::assertResponseIsSuccessful('Un refus de forme ne consomme pas le lien.');
        } finally {
            $this->rollback();
        }
    }

    /** @return array{0: KernelBrowser, 1: Utilisateur} */
    private function demarrer(): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $compte = new Utilisateur('agent.oubli@example.test', 'temp', 'Oubli', 'Agent');
        $compte->setMotDePasseHash($container->get(UserPasswordHasherInterface::class)->hashPassword($compte, self::ANCIEN));
        $compte->marquerMdpChange();
        $this->em->persist($compte);
        $this->em->flush();

        return [$client, $compte];
    }

    private function demander(KernelBrowser $client, string $email): void
    {
        $client->request('POST', '/api/v1/auth/mot-de-passe-oublie', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email], \JSON_THROW_ON_ERROR));
    }

    private function reinitialiser(KernelBrowser $client, string $jeton, string $motDePasse): void
    {
        $client->request('POST', '/api/v1/auth/reinitialisation', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'jeton' => $jeton,
            'nouveauMotDePasse' => $motDePasse,
        ], \JSON_THROW_ON_ERROR));
    }

    private function connecter(KernelBrowser $client, string $motDePasse): void
    {
        $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'agent.oubli@example.test',
            'motDePasse' => $motDePasse,
        ], \JSON_THROW_ON_ERROR));
    }

    /** Le jeton se lit dans le lien du courriel, après le `#`. */
    private function jetonDuDernierLien(string $destinataire): string
    {
        $liens = $this->courriels(self::SUJET_LIEN);
        self::assertNotEmpty($liens, 'Un courriel de récupération est parti.');
        $courriel = end($liens);
        self::assertSame($destinataire, $courriel->getTo()[0]->getAddress());
        self::assertSame(1, preg_match('~/reinitialiser-mot-de-passe#([A-Za-z0-9_-]{43})~', (string) $courriel->getTextBody(), $m), 'Le lien figure en clair dans la version texte.');

        return $m[1];
    }

    /** @return list<Email> */
    private function courriels(string $sujet): array
    {
        return array_values(array_filter(
            self::getMailerMessages(),
            static fn ($message) => $message instanceof Email && $message->getSubject() === $sujet,
        ));
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
