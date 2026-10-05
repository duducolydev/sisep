<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Exercice;
use App\Entity\Projet;
use App\Entity\Structure;
use App\Enum\PeriodeTrimestre;
use App\Enum\TypeStructure;
use App\Repository\RapportPeriodiqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * § 5.5 du brief : génération automatique, idempotente (une seule ligne par
 * structure×exercice×trimestre, même si la commande tourne plusieurs fois
 * le même jour — cas réel d'un cron quotidien).
 */
final class GenererRapportsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    public function testLaCommandeEstIdempotente(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        try {
            $projet = new Projet('TESTRAPPORTS', 'Projet rapports', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
            $this->em->persist($projet);
            $annee = (int) (new \DateTimeImmutable('today'))->format('Y');
            $exercice = new Exercice($projet, $annee, new \DateTimeImmutable("{$annee}-01-01"), new \DateTimeImmutable("{$annee}-12-31"));
            $this->em->persist($exercice);
            $structure = new Structure('TST-RAP', 'Structure de test', TypeStructure::DIRECTION_ANSD);
            $this->em->persist($structure);
            $this->em->flush();

            $application = new Application(self::$kernel);
            $command = $application->find('app:rapports:generer');
            $tester = new CommandTester($command);

            $tester->execute([]);
            self::assertSame(0, $tester->getStatusCode());

            /** @var RapportPeriodiqueRepository $rapports */
            $rapports = $container->get(RapportPeriodiqueRepository::class);
            $periodeCourante = match (true) {
                (int) (new \DateTimeImmutable('today'))->format('n') <= 3 => PeriodeTrimestre::T1,
                (int) (new \DateTimeImmutable('today'))->format('n') <= 6 => PeriodeTrimestre::T2,
                (int) (new \DateTimeImmutable('today'))->format('n') <= 9 => PeriodeTrimestre::T3,
                default => PeriodeTrimestre::T4,
            };

            self::assertNotNull($rapports->trouverPour($structure, $exercice, $periodeCourante), 'Un rapport doit être créé pour la structure active.');
            $total = \count($rapports->findBy(['structure' => $structure, 'exercice' => $exercice]));
            self::assertSame(1, $total);

            // Deuxième exécution le même jour : ne doit rien dupliquer.
            $tester->execute([]);
            self::assertSame(0, $tester->getStatusCode());
            $totalApres = \count($rapports->findBy(['structure' => $structure, 'exercice' => $exercice]));
            self::assertSame(1, $totalApres, 'Une deuxième exécution ne doit pas créer de doublon (cron quotidien idempotent).');
        } finally {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
        }
    }
}
