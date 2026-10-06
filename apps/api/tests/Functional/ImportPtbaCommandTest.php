<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Utilisateur;
use App\Repository\ActiviteRepository;
use App\Repository\ComposanteRepository;
use App\Repository\DotationRepository;
use App\Repository\SousActiviteRepository;
use App\Tests\Support\ConstructeurClasseur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Import du PTBA réel : c'est par là que la base de production est
 * alimentée, et les erreurs d'un import budgétaire se voient tard.
 *
 * Trois invariants sous test : la hiérarchie du classeur devient celle du
 * projet, seules les feuilles de l'arbre sont dotées — doter aussi les
 * niveaux supérieurs compterait deux fois le même argent — et relancer
 * l'import ne duplique rien.
 */
final class ImportPtbaCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private string $fichier = '';

    protected function tearDown(): void
    {
        if ('' !== $this->fichier && is_file($this->fichier)) {
            unlink($this->fichier);
        }
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testImporteLaHierarchieEtNeDoteQueLesFeuilles(): void
    {
        $tester = $this->demarrer();

        try {
            $tester->execute(['fichier' => $this->fichier, '--annee' => '2026', '--auteur' => 'import.ptba@example.test']);
            $tester->assertCommandIsSuccessful();
            $this->em->clear();

            $composantes = static::getContainer()->get(ComposanteRepository::class);
            $activites = static::getContainer()->get(ActiviteRepository::class);
            $sousActivites = static::getContainer()->get(SousActiviteRepository::class);
            $dotations = static::getContainer()->get(DotationRepository::class);

            self::assertNotNull($composantes->findOneBy(['code' => '9']), 'La composante du classeur doit exister, codée comme lui.');

            $activiteDotee = $activites->findOneBy(['code' => '9.1.2']);
            self::assertNotNull($activiteDotee);
            $activitePorteuse = $activites->findOneBy(['code' => '9.1.1']);
            self::assertNotNull($activitePorteuse);

            $exercice = $this->exercice();
            self::assertNotNull(
                $dotations->trouverPourCibleExercice($activiteDotee, $exercice),
                'Une activité sans sous-activité porte elle-même sa dotation.',
            );
            self::assertNull(
                $dotations->trouverPourCibleExercice($activitePorteuse, $exercice),
                'Une activité découpée en sous-activités ne doit pas être dotée : son montant est la somme des leurs.',
            );

            $sousActivite = null;
            foreach ($sousActivites->findBy(['activite' => $activitePorteuse]) as $candidate) {
                if ('Collecte principale' === $candidate->getLibelle()) {
                    $sousActivite = $candidate;
                }
            }
            self::assertNotNull($sousActivite);

            $dotation = $dotations->trouverPourCibleExercice($sousActivite, $exercice);
            self::assertNotNull($dotation);
            self::assertSame(3_000_000, $dotation->getMontant()->versFcfa());
            self::assertSame(1_000_000, $dotation->getQ1()?->versFcfa());
            self::assertSame(2_500_000, $dotation->getMontantValideCopil()?->versFcfa());
            self::assertSame('Révision de mars', $dotation->getCommentaireRevision(), "Le commentaire vient de l'onglet d'origine.");

            // Ligne à 0 FCFA : planifiée, non budgétée cette année.
            $nonBudgetee = $sousActivites->findOneBy(['libelle' => 'Phase non financée']);
            self::assertNotNull($nonBudgetee);
            self::assertNull($dotations->trouverPourCibleExercice($nonBudgetee, $exercice), 'Aucune dotation à zéro.');
        } finally {
            $this->rollback();
        }
    }

    public function testRelancerLImportNeDupliqueRien(): void
    {
        $tester = $this->demarrer();

        try {
            $tester->execute(['fichier' => $this->fichier, '--annee' => '2026', '--auteur' => 'import.ptba@example.test']);
            $tester->assertCommandIsSuccessful();
            $this->em->clear();

            $tester->execute(['fichier' => $this->fichier, '--annee' => '2026', '--auteur' => 'import.ptba@example.test']);
            $tester->assertCommandIsSuccessful();
            $this->em->clear();

            $composantes = static::getContainer()->get(ComposanteRepository::class);
            $activites = static::getContainer()->get(ActiviteRepository::class);

            self::assertCount(1, $composantes->findBy(['code' => '9']));
            self::assertCount(1, $activites->findBy(['code' => '9.1.1']));
            self::assertStringContainsString('Dotations créées 0', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '', 'Au second passage, les dotations sont mises à jour, pas recréées.');
        } finally {
            $this->rollback();
        }
    }

    private function demarrer(): CommandTester
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $auteur = new Utilisateur('import.ptba@example.test', 'temp', 'Import', 'PTBA');
        $auteur->setMotDePasseHash('indifferent');
        $this->em->persist($auteur);
        $this->em->flush();

        $this->fichier = (new ConstructeurClasseur())
            ->ajouterFeuille('PTBA-2026_RÉADAPTÉ', [
                ['C' => 'Budget LFR+RELIQUAT 2025', 'G' => '8000000'],
                ['A' => 'N°', 'B' => 'Intitulé du projet', 'C' => 'TRIM 1', 'D' => 'TRIM 2', 'E' => 'TRIM 3', 'F' => 'TRIM 4', 'G' => 'Budget total FCFA révisé mars 2026)', 'H' => 'Budget total FCFA validé par le COPIL'],
                ['A' => '9.', 'B' => 'Composante de test', 'C' => '1000000', 'G' => '8000000', 'H' => '7500000'],
                ['A' => '9.1.', 'B' => 'Sous-composante de test', 'C' => '1000000', 'G' => '8000000', 'H' => '7500000'],
                ['A' => '9.1.1.', 'B' => 'Activité découpée', 'C' => '1000000', 'G' => '3000000', 'H' => '2500000'],
                ['A' => '9.1.1.1', 'B' => 'Collecte principale', 'C' => '1000000', 'D' => '2000000', 'G' => '3000000', 'H' => '2500000'],
                ['A' => '9.1.1.2', 'B' => 'Phase non financée', 'C' => '0', 'G' => '0', 'H' => '0'],
                ['A' => '9.1.2.', 'B' => 'Activité sans découpage', 'C' => '0', 'G' => '5000000', 'H' => '5000000'],
            ])
            ->ajouterFeuille('PTBA-2026', [
                ['A' => 'N°', 'B' => 'Intitulé du projet', 'I' => 'Budget total FCFA révisé mars 2026)', 'J' => 'Commentaires sur les changements'],
                ['A' => '9.1.1.1', 'B' => 'Collecte principale', 'I' => '3000000', 'J' => 'Révision de mars'],
            ])
            ->ecrire();

        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:ptba:importer'));
    }

    private function exercice(): \App\Entity\Exercice
    {
        $exercice = $this->em->getRepository(\App\Entity\Exercice::class)->findOneBy(['annee' => 2026]);
        self::assertNotNull($exercice);

        return $exercice;
    }

    private function rollback(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
    }
}
