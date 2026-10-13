<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Amorcage\ReferentielApplicatif;
use App\Command\SeedDatabaseCommand;
use App\Entity\Utilisateur;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le jeu de démonstration vide toutes les tables avant de semer, comptes
 * utilisateurs compris. En production, il effacerait tout ce que l'UGP a
 * saisi : la commande doit refuser, et ne rien toucher en refusant.
 */
final class SeedRefuseEnProductionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testRefuseEnProductionSansRienEffacer(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        // Un compte créé par l'UGP, qui doit survivre à la tentative.
        $compte = new Utilisateur('agent.ugp@example.test', 'temp', 'Agent', 'UGP');
        $compte->setMotDePasseHash('indifferent');
        $this->em->persist($compte);
        $this->em->flush();

        $commande = new SeedDatabaseCommand(
            $container->get(ReferentielApplicatif::class),
            $this->em,
            $container->get(Connection::class),
            $container->get(UserPasswordHasherInterface::class),
            '',
            'prod',
        );
        $tester = new CommandTester($commande);
        $code = $tester->execute(['--force' => true]);

        self::assertSame(Command::FAILURE, $code, 'Même avec --force, la commande doit refuser en production.');
        self::assertStringContainsString('app:amorcer', $tester->getDisplay(), 'Le refus doit indiquer la bonne commande.');

        $this->em->clear();
        self::assertNotNull(
            $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'agent.ugp@example.test']),
            'Le refus doit avoir lieu avant toute écriture : le compte doit être intact.',
        );
    }
}
