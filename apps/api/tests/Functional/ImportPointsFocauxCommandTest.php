<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Projet;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Enum\TypeStructure;
use App\Repository\PointFocalRepository;
use App\Repository\StructureRepository;
use App\Tests\Support\ConstructeurClasseur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Import des points focaux : il apporte au projet ce que le PTBA ne dit pas,
 * c'est-à-dire qui répond de quelle ligne.
 *
 * Les invariants sous test sont ceux qui coûteraient cher en production :
 * une personne citée sur plusieurs lignes ne doit pas devenir plusieurs
 * fiches, et une structure absente doit être créée plutôt que de faire
 * échouer l'import.
 */
final class ImportPointsFocauxCommandTest extends KernelTestCase
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

    public function testCreeLesStructuresManquantesEtRattacheLesLignesDuPtba(): void
    {
        [$tester, $fixture] = $this->demarrer();

        try {
            $tester->execute(['fichier' => $this->fichier]);
            $tester->assertCommandIsSuccessful();
            $this->em->clear();

            $structures = static::getContainer()->get(StructureRepository::class);
            $pointsFocaux = static::getContainer()->get(PointFocalRepository::class);

            $dtest = $structures->findOneBy(['sigle' => 'DTEST']);
            self::assertNotNull($dtest, 'La direction absente de la base doit être créée depuis le classeur.');
            self::assertSame(TypeStructure::DIRECTION_ANSD, $dtest->getType(), 'Une direction sous tutelle ANSD est une direction de l\'ANSD.');

            $partenaire = $structures->findOneBy(['sigle' => 'DPART']);
            self::assertNotNull($partenaire);
            self::assertSame(TypeStructure::PARTENAIRE_NATIONAL, $partenaire->getType(), 'Hors ANSD, la structure est un partenaire national.');

            // Une personne citée deux fois n'a qu'une fiche.
            $fiches = $pointsFocaux->findBy(['structure' => $dtest]);
            self::assertCount(1, $fiches);
            self::assertSame('Awa Diouf', $fiches[0]->getNomComplet());
            self::assertSame('770000001', $fiches[0]->getTelephone());
            self::assertSame('awa.diouf@ansd.sn', $fiches[0]->getEmail());

            // Le titre entre parenthèses devient la fonction, pas le nom.
            $fichesPartenaire = $pointsFocaux->findBy(['structure' => $partenaire]);
            self::assertCount(1, $fichesPartenaire);
            self::assertSame('Modou Fall', $fichesPartenaire[0]->getNomComplet());
            self::assertSame('Chef de projet', $fichesPartenaire[0]->getFonction());

            // La sous-activité désignée porte désormais sa structure, et
            // l'activité quitte le responsable par défaut.
            $this->em->clear();
            $sousActivite = $this->em->getRepository(SousActivite::class)->findOneBy(['libelle' => 'Collecte principale']);
            self::assertNotNull($sousActivite);
            self::assertSame('DTEST', $sousActivite->getStructureResponsable()?->getSigle());

            $activite = $this->em->getRepository(Activite::class)->findOneBy(['code' => $fixture['codeActivite']]);
            self::assertNotNull($activite);
            self::assertSame('DTEST', $activite->getStructureResponsable()->getSigle());
        } finally {
            $this->rollback();
        }
    }

    public function testSignaleLesCodesAbsentsDuPtbaSansEchouer(): void
    {
        [$tester] = $this->demarrer(codeInconnu: true);

        try {
            $tester->execute(['fichier' => $this->fichier]);
            $tester->assertCommandIsSuccessful();

            $affichage = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
            self::assertStringContainsString('sans correspondance dans le PTBA', $affichage);
            self::assertStringContainsString('8.4.9.1', $affichage, "Le code orphelin doit être nommé, pour qu'on sache lequel vérifier.");
        } finally {
            $this->rollback();
        }
    }

    /**
     * @return array{0: CommandTester, 1: array<string, string>}
     */
    private function demarrer(bool $codeInconnu = false): array
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        // Responsable par défaut posé par l'import du PTBA : c'est lui que
        // l'import des contacts doit remplacer.
        $ansd = $this->em->getRepository(Structure::class)->findOneBy(['sigle' => 'ANSD'])
            ?? new Structure('ANSD', 'Agence Nationale de la Statistique et de la Démographie', TypeStructure::CHEF_DE_FILE);
        $this->em->persist($ansd);

        $projet = new Projet('TESTPF', 'Projet points focaux', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, '8', 'Composante points focaux');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, '8.4', 'Sous-composante points focaux');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, '8.4.1', 'Activité points focaux', $ansd);
        $this->em->persist($activite);
        $sousActivite = new SousActivite($activite, 'Collecte principale');
        $this->em->persist($sousActivite);
        $this->em->flush();

        $codeSuivi = $codeInconnu ? '8.4.9.1' : '8.4.1.1';

        $this->fichier = (new ConstructeurClasseur())
            ->ajouterFeuille('PHASAOC', [
                ['B' => 'Information des Points Focaux'],
                ['A' => '1. Section de test'],
                ['A' => 'Activités retenues dans le PTBA 2026', 'C' => 'Prénoms & Nom', 'D' => 'Structure / Direction', 'E' => 'Téléphone', 'F' => 'E-mail'],
                ['A' => $codeSuivi, 'B' => 'Collecte principale', 'C' => 'Awa Diouf', 'D' => 'ANSD / DTEST', 'E' => '770000001', 'F' => 'awa.diouf@ansd.sn'],
                ['A' => '8.4.1.2', 'B' => 'Autre ligne confiée à la même personne', 'C' => 'Awa Diouf', 'D' => 'ANSD / DTEST', 'E' => '770000001', 'F' => 'awa.diouf@ansd.sn'],
                ['A' => '8.4.1.3', 'B' => 'Ligne partenaire', 'C' => 'Modou Fall (Chef de projet)', 'D' => 'Ministère de test / DPART', 'E' => '770000002', 'F' => 'modou.fall@test.sn'],
            ])
            ->ecrire();

        $application = new Application(self::$kernel);

        return [new CommandTester($application->find('app:points-focaux:importer')), ['codeActivite' => '8.4.1']];
    }

    private function rollback(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
    }
}
