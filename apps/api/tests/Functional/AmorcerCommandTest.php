<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Amorcage\ReferentielApplicatif;
use App\Command\AmorcerCommand;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Amorçage d'une base de production. C'est la seule porte d'entrée d'une
 * installation neuve : si elle échoue, personne ne peut se connecter, et
 * c'est exactement ce qui s'est produit au premier déploiement — mot de
 * passe d'amorçage perdu, et aucun moyen d'en obtenir un autre.
 */
final class AmorcerCommandTest extends KernelTestCase
{
    private const EMAIL = 'admin.amorcage@example.test';

    private EntityManagerInterface $em;

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testCreeLeCompteSIlManqueMemeSurUneBaseDejaAmorcee(): void
    {
        $tester = $this->demarrer();

        // La base de test porte déjà un référentiel : c'est le cas d'une
        // première exécution faite sans ADMIN_EMAIL, puis relancée.
        $tester->execute(['--mot-de-passe' => 'Temporaire123!']);
        $tester->assertCommandIsSuccessful();

        $compte = $this->compte();
        self::assertNotNull($compte, 'Le compte doit être créé même quand le référentiel existe déjà.');
        self::assertTrue($compte->doitChangerMdp(), 'Un mot de passe d\'amorçage ne sert qu\'une fois.');
        self::assertTrue($this->hacheur()->isPasswordValid($compte, 'Temporaire123!'));
        self::assertStringContainsString('Temporaire123!', $tester->getDisplay(), 'Le mot de passe doit être affiché, il n\'est écrit nulle part.');
    }

    public function testNeTouchePasAUnCompteExistantSansDemandeExplicite(): void
    {
        $tester = $this->demarrer();
        $tester->execute(['--mot-de-passe' => 'Premier123!']);
        $this->compte()?->marquerMdpChange();
        $this->em->flush();

        // Relancer la commande à chaque déploiement ne doit jamais changer
        // le mot de passe de l'administrateur dans son dos.
        $tester->execute(['--mot-de-passe' => 'Second456!']);
        $tester->assertCommandIsSuccessful();

        $compte = $this->compte();
        self::assertNotNull($compte);
        self::assertTrue($this->hacheur()->isPasswordValid($compte, 'Premier123!'));
        self::assertFalse($compte->doitChangerMdp());
        self::assertStringContainsString('--reinitialiser-mot-de-passe', $tester->getDisplay(), 'Le recours doit être indiqué.');
    }

    public function testReinitialiseLeMotDePasseSurDemande(): void
    {
        $tester = $this->demarrer();
        $tester->execute(['--mot-de-passe' => 'Perdu123!']);
        $this->compte()?->marquerMdpChange();
        $this->em->flush();

        $tester->execute(['--reinitialiser-mot-de-passe' => true, '--mot-de-passe' => 'Nouveau789!']);
        $tester->assertCommandIsSuccessful();

        $compte = $this->compte();
        self::assertNotNull($compte);
        self::assertTrue($this->hacheur()->isPasswordValid($compte, 'Nouveau789!'));
        self::assertFalse($this->hacheur()->isPasswordValid($compte, 'Perdu123!'));
        self::assertTrue($compte->doitChangerMdp(), 'Un mot de passe réinitialisé par l\'exploitant doit être changé à la connexion.');
    }

    private function demarrer(): CommandTester
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        // Instanciée à la main pour lui donner une adresse : celle de
        // l'environnement de test est vide.
        $commande = new AmorcerCommand($container->get(ReferentielApplicatif::class), $this->em, self::EMAIL);

        return new CommandTester($commande);
    }

    private function compte(): ?Utilisateur
    {
        return $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL]);
    }

    private function hacheur(): UserPasswordHasherInterface
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class);
    }
}
