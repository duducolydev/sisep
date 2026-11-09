<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\Exercice;
use App\Entity\JournalActivite;
use App\Entity\PieceJointe;
use App\Entity\Projet;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Enum\NatureEngagement;
use App\Enum\TypeStructure;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Purge des essais antérieurs à la mise en service : elle efface du budget et
 * du journal, sans retour possible hors sauvegarde.
 *
 * À verrouiller : rien sans --force, les dotations importées intactes, une
 * seule ligne de trace, et aucun effacement dès que l'écriture n'a plus l'air
 * d'un essai (saisie récente, statut vivant, dépense, justificatif).
 */
final class PurgerEssaisBudgetCommandTest extends KernelTestCase
{
    private const JOUR_DES_ESSAIS = '2026-10-13';
    private const MONTANT = 100_000;

    private EntityManagerInterface $em;

    private Utilisateur $saisisseur;

    private Utilisateur $responsable;

    private Exercice $exercice;

    private Activite $activite;

    private SousActivite $ligne;

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testSansForceRienNEstEcrit(): void
    {
        $tester = $this->demarrer();
        $dotation = $this->dotationEssai();
        $engagement = $this->engagementEssai();

        self::assertSame(Command::SUCCESS, $this->purger($tester, 1, 1, force: false));

        $this->em->clear();
        self::assertNotNull($this->em->find(Dotation::class, $dotation));
        self::assertNotNull($this->em->find(Engagement::class, $engagement));
        self::assertNull($this->trace());
        self::assertStringContainsString('Simulation', $tester->getDisplay());
    }

    public function testLaPurgeEffaceLesEssaisEtNeLaisseQuUneTrace(): void
    {
        $tester = $this->demarrer();
        $essai = $this->dotationEssai();
        $premier = $this->engagementEssai();
        $second = $this->engagementEssai();
        $importee = $this->dotationImportee();

        self::assertSame(Command::SUCCESS, $this->purger($tester, 1, 2));

        $this->em->clear();
        self::assertNull($this->em->find(Dotation::class, $essai));
        self::assertNull($this->em->find(Engagement::class, $premier));
        self::assertNull($this->em->find(Engagement::class, $second));
        self::assertSame(0, $this->lignesJournal('dotation', $essai));
        self::assertSame(0, $this->lignesJournal('engagement', $premier));
        self::assertSame(0, $this->lignesJournal('engagement', $second));

        self::assertNotNull($this->em->find(Dotation::class, $importee), 'Une dotation importée reste en place.');
        self::assertSame(1, $this->lignesJournal('dotation', $importee), 'Sa ligne de journal aussi.');

        $trace = $this->trace();
        self::assertNotNull($trace);
        self::assertSame($this->responsable->getEmail(), $trace->getUtilisateur()?->getEmail());
        self::assertNull($trace->getEntiteId());
        $resume = $trace->getAvant() ?? [];
        self::assertSame(1, $resume['dotations'] ?? null);
        self::assertSame(self::MONTANT, $resume['montantDotations'] ?? null);
        self::assertSame(2, $resume['engagements'] ?? null);
        self::assertSame(2 * self::MONTANT, $resume['montantEngagements'] ?? null);
        self::assertSame([$essai], $resume['identifiantsDotations'] ?? null);
        self::assertSame(5, $resume['lignesJournalPurgees'] ?? null, 'Une saisie de dotation, puis saisie et annulation de deux engagements.');

        self::assertSame(Command::FAILURE, $this->purger($tester, 1, 2), 'Un second passage ne trouve plus rien.');
    }

    public function testLaPurgeInvalideLArbreEnCache(): void
    {
        $tester = $this->demarrer();
        $this->dotationEssai();

        $cache = static::getContainer()->get(TagAwareCacheInterface::class);
        $cache->delete('test_purge_essais');
        $cache->get('test_purge_essais', static function (ItemInterface $item): string {
            $item->tag('arbre');

            return 'avant la purge';
        });

        self::assertSame(Command::SUCCESS, $this->purger($tester, 1, 0));

        self::assertSame('après la purge', $cache->get('test_purge_essais', static fn (): string => 'après la purge'), 'Le notifié retiré ne doit pas survivre une heure dans l\'arbre.');
        $cache->delete('test_purge_essais');
    }

    public function testUnPerimetreDifferentDeLAnnonceEstRefuse(): void
    {
        $tester = $this->demarrer();
        $dotation = $this->dotationEssai();

        self::assertSame(Command::FAILURE, $this->purger($tester, 2, 0));
        self::assertStringContainsString('Périmètre inattendu', $this->affichage($tester));
        $this->assertRienEfface(Dotation::class, $dotation);
    }

    public function testUneSaisieApresLaDateLimiteResteEnDehors(): void
    {
        $tester = $this->demarrer();
        $essai = $this->dotationEssai();
        $reelle = $this->saisir(new Dotation($this->nouvelleLigne(), $this->exercice, Montant::fromFcfa(self::MONTANT), $this->saisisseur));

        self::assertSame(Command::SUCCESS, $this->purger($tester, 1, 0));

        $this->em->clear();
        self::assertNull($this->em->find(Dotation::class, $essai));
        self::assertNotNull($this->em->find(Dotation::class, $reelle), 'Une saisie postérieure n\'est pas un essai.');
    }

    public function testUnEngagementEnBrouillonEstRefuse(): void
    {
        $tester = $this->demarrer();
        $engagement = $this->saisir($this->nouvelEngagement());
        $this->antidater('engagement', $engagement);

        self::assertSame(Command::FAILURE, $this->purger($tester, 0, 1));
        self::assertStringContainsString('brouillon', $this->affichage($tester));
        $this->assertRienEfface(Engagement::class, $engagement);
    }

    public function testUnEngagementValideEstRefuse(): void
    {
        $tester = $this->demarrer();
        $objet = $this->nouvelEngagement();
        $engagement = $this->saisir($objet);
        $objet->valider($this->responsable);
        $this->em->flush();
        $this->antidater('engagement', $engagement);

        self::assertSame(Command::FAILURE, $this->purger($tester, 0, 1));
        self::assertStringContainsString('valide', $this->affichage($tester));
        $this->assertRienEfface(Engagement::class, $engagement);
    }

    public function testUneModificationApresLaDateLimiteEstRefusee(): void
    {
        $tester = $this->demarrer();
        $engagement = $this->engagementEssai();

        $this->connecter($this->saisisseur);
        $objet = $this->em->find(Engagement::class, $engagement);
        self::assertNotNull($objet);
        $objet->setObservations('Repris après la mise en service');
        $this->em->flush();
        $this->deconnecter();

        self::assertSame(Command::FAILURE, $this->purger($tester, 0, 1));
        self::assertStringContainsString('modifiée après la date limite', $this->affichage($tester));
        $this->assertRienEfface(Engagement::class, $engagement);
    }

    public function testUneDepenseSurUnEngagementAnnuleEstRefusee(): void
    {
        $tester = $this->demarrer();
        $engagement = $this->engagementEssai();
        $objet = $this->em->find(Engagement::class, $engagement);
        self::assertNotNull($objet);
        $this->em->persist(new Depense($objet, Montant::fromFcfa(self::MONTANT), new \DateTimeImmutable(self::JOUR_DES_ESSAIS), $this->saisisseur));
        $this->em->flush();

        self::assertSame(Command::FAILURE, $this->purger($tester, 0, 1));
        self::assertStringContainsString('dépenses', $this->affichage($tester));
        $this->assertRienEfface(Engagement::class, $engagement);
    }

    public function testUnJustificatifEstRefuse(): void
    {
        $tester = $this->demarrer();
        $engagement = $this->engagementEssai();
        $this->em->persist(new PieceJointe('engagement', $engagement, 'bon.pdf', 'essais/bon.pdf', 'application/pdf', 1024, $this->saisisseur));
        $this->em->flush();

        self::assertSame(Command::FAILURE, $this->purger($tester, 0, 1));
        self::assertStringContainsString('justificatif', $this->affichage($tester));
        $this->assertRienEfface(Engagement::class, $engagement);
    }

    public function testUneLigneEngageeDepuisProtegeSaDotation(): void
    {
        $tester = $this->demarrer();
        $dotation = $this->dotationEssai();
        // Saisi après la date limite : hors périmètre, mais il compte sur ce plafond.
        $this->saisir($this->nouvelEngagement());

        self::assertSame(Command::FAILURE, $this->purger($tester, 1, 0));
        self::assertStringContainsString('porte des engagements', $this->affichage($tester));
        $this->assertRienEfface(Dotation::class, $dotation);
    }

    public function testUnExerciceClotureEstRefuse(): void
    {
        $tester = $this->demarrer();
        $dotation = $this->dotationEssai();
        $this->exercice->cloturer();
        $this->em->flush();

        self::assertSame(Command::FAILURE, $this->purger($tester, 1, 0));
        self::assertStringContainsString('clôturé', $this->affichage($tester));
        $this->assertRienEfface(Dotation::class, $dotation);
    }

    public function testDesOptionsInvalidesSontRefusees(): void
    {
        $tester = $this->demarrer();
        $dotation = $this->dotationEssai();

        self::assertSame(Command::FAILURE, $tester->execute(['--jusqu-au' => '13/10/2026', '--dotations' => '1', '--engagements' => '0', '--auteur' => $this->responsable->getEmail(), '--force' => true]));
        self::assertSame(Command::FAILURE, $tester->execute(['--jusqu-au' => self::JOUR_DES_ESSAIS, '--engagements' => '0', '--auteur' => $this->responsable->getEmail(), '--force' => true]));
        self::assertSame(Command::FAILURE, $tester->execute(['--jusqu-au' => self::JOUR_DES_ESSAIS, '--dotations' => '1', '--engagements' => '0', '--auteur' => 'personne@example.test', '--force' => true]));
        $this->assertRienEfface(Dotation::class, $dotation);
    }

    private function demarrer(): CommandTester
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->saisisseur = new Utilisateur('saisie.purge@example.test', 'temp', 'Saisie', 'Test');
        $this->saisisseur->setMotDePasseHash('indifferent');
        $this->em->persist($this->saisisseur);

        $this->responsable = new Utilisateur('responsable.purge@example.test', 'temp', 'Responsable', 'Test');
        $this->responsable->setMotDePasseHash('indifferent');
        $this->em->persist($this->responsable);

        $structure = new Structure('TST-PE', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);
        $projet = new Projet('TESTPE', 'Projet purge essais', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $this->exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($this->exercice);
        $composante = new Composante($projet, 'CPE', 'Composante purge');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CPE.1', 'Sous-composante purge');
        $this->em->persist($sousComposante);
        $this->activite = new Activite($sousComposante, 'CPE.1.1', 'Activité purge', $structure);
        $this->em->persist($this->activite);
        $this->ligne = new SousActivite($this->activite, 'Ligne des essais');
        $this->em->persist($this->ligne);
        $this->em->flush();

        // Le jeu de test partage sa base : on ne purge que ce test.
        $this->neutraliserLesSaisiesExistantes();

        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:budget:purger-essais'));
    }

    /** Les saisies déjà en base de test ne doivent pas entrer dans le périmètre. */
    private function neutraliserLesSaisiesExistantes(): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE journal_activite SET utilisateur_id = NULL WHERE entite_type IN ('dotation', 'engagement') AND action = 'creation'",
        );
    }

    private function dotationEssai(): int
    {
        $id = $this->saisir(new Dotation($this->ligne, $this->exercice, Montant::fromFcfa(self::MONTANT), $this->saisisseur));
        $this->antidater('dotation', $id);

        return $id;
    }

    /** Saisi puis annulé, comme les essais du 13/10/2026. */
    private function engagementEssai(): int
    {
        $engagement = $this->nouvelEngagement();
        $id = $this->saisir($engagement);

        $this->connecter($this->saisisseur);
        $engagement->annuler();
        $this->em->flush();
        $this->deconnecter();

        $this->antidater('engagement', $id);

        return $id;
    }

    /** Pas de jeton au moment du flush : c'est ainsi qu'écrit l'import du PTBA. */
    private function dotationImportee(): int
    {
        $dotation = new Dotation($this->nouvelleLigne(), $this->exercice, Montant::fromFcfa(self::MONTANT), $this->saisisseur);
        $this->em->persist($dotation);
        $this->em->flush();
        $id = (int) $dotation->getId();
        $this->antidater('dotation', $id);

        return $id;
    }

    private function nouvelEngagement(): Engagement
    {
        return new Engagement($this->ligne, $this->exercice, NatureEngagement::TRAVAUX, Montant::fromFcfa(self::MONTANT), new \DateTimeImmutable(self::JOUR_DES_ESSAIS), $this->saisisseur);
    }

    private function nouvelleLigne(): SousActivite
    {
        $ligne = new SousActivite($this->activite, 'Autre ligne '.uniqid());
        $this->em->persist($ligne);
        $this->em->flush();

        return $ligne;
    }

    private function saisir(Dotation|Engagement $ecriture): int
    {
        $this->connecter($this->saisisseur);
        $this->em->persist($ecriture);
        $this->em->flush();
        $this->deconnecter();

        return (int) $ecriture->getId();
    }

    private function connecter(Utilisateur $utilisateur): void
    {
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($utilisateur, 'test', $utilisateur->getRoles()));
    }

    private function deconnecter(): void
    {
        static::getContainer()->get(TokenStorageInterface::class)->setToken(null);
    }

    private function antidater(string $type, int $id): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE journal_activite SET cree_le = ? WHERE entite_type = ? AND entite_id = ?',
            [self::JOUR_DES_ESSAIS.' 12:00:00', $type, $id],
        );
    }

    private function purger(CommandTester $tester, int $dotations, int $engagements, bool $force = true): int
    {
        return $tester->execute([
            '--jusqu-au' => self::JOUR_DES_ESSAIS,
            '--dotations' => (string) $dotations,
            '--engagements' => (string) $engagements,
            '--auteur' => $this->responsable->getEmail(),
            '--force' => $force,
        ]);
    }

    private function trace(): ?JournalActivite
    {
        return $this->em->getRepository(JournalActivite::class)->findOneBy(['entiteType' => 'budget', 'action' => 'purge']);
    }

    private function lignesJournal(string $type, int $id): int
    {
        return \count($this->em->getRepository(JournalActivite::class)->findBy(['entiteType' => $type, 'entiteId' => $id]));
    }

    /** @param class-string $classe */
    private function assertRienEfface(string $classe, int $id): void
    {
        $this->em->clear();
        self::assertNotNull($this->em->find($classe, $id));
        self::assertNull($this->trace(), 'Un refus ne laisse aucune trace de purge.');
    }

    /** SymfonyStyle replie les messages longs : l'assertion porte sur le texte, pas sur la mise en page. */
    private function affichage(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
