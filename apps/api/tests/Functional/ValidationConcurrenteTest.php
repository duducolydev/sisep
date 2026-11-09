<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Budget\AnnulationEcritureValideeService;
use App\Budget\DepenseValidationService;
use App\Budget\DotationRevisionService;
use App\Budget\EngagementValidationService;
use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\Exercice;
use App\Entity\Projet;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Enum\NatureEngagement;
use App\Enum\TypeStructure;
use App\Exception\ContrainteBudgetaireException;
use App\ValueObject\Montant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le plafond tient-il quand deux personnes valident au même instant (§ 5.1) ?
 *
 * Seule une seconde connexion MySQL le prouve : en REPEATABLE READ, une lecture
 * faite avant le verrou fige l'instantané de la transaction, et la somme
 * calculée après l'attente ignore ce que l'autre valideur vient de valider. Les
 * tests à transaction annulée, sur une seule connexion, ne peuvent pas le voir.
 *
 * Ces écritures sont donc réellement validées en base de test, et retirées à la
 * fin de chaque test.
 */
final class ValidationConcurrenteTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connexion;
    private int $dernierJournal = 0;

    /** @var array<string, int> */
    private array $ids = [];

    private Utilisateur $saisie;
    private Utilisateur $valideur;
    private Utilisateur $autreValideur;
    private Exercice $exercice;
    private Activite $activite;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connexion = $this->em->getConnection();
        $this->dernierJournal = (int) $this->connexion->fetchOne('SELECT COALESCE(MAX(id), 0) FROM journal_activite');

        $suffixe = (string) random_int(100000, 999999);
        $structure = new Structure('CC'.$suffixe, 'Structure concurrence', TypeStructure::DIRECTION_ANSD);
        $this->saisie = new Utilisateur("saisie.{$suffixe}@example.test", 'x', 'Concurrence', 'Saisie');
        $this->valideur = new Utilisateur("valideur.{$suffixe}@example.test", 'x', 'Concurrence', 'Valideur');
        $this->autreValideur = new Utilisateur("autre.{$suffixe}@example.test", 'x', 'Concurrence', 'Autre');
        $projet = new Projet('CC'.$suffixe, 'Projet concurrence', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $composante = new Composante($projet, 'CC', 'Composante');
        $sousComposante = new SousComposante($composante, 'CC.1', 'Sous-composante');
        $this->activite = new Activite($sousComposante, 'CC.1.1', 'Activité', $structure);
        foreach ([$structure, $this->saisie, $this->valideur, $this->autreValideur, $projet, $this->exercice, $composante, $sousComposante, $this->activite] as $objet) {
            $this->em->persist($objet);
        }
        $this->em->flush();

        $this->ids = [
            'structure' => (int) $structure->getId(),
            'projet' => (int) $projet->getId(),
            'exercice' => (int) $this->exercice->getId(),
            'composante' => (int) $composante->getId(),
            'sousComposante' => (int) $sousComposante->getId(),
            'activite' => (int) $this->activite->getId(),
            'saisie' => (int) $this->saisie->getId(),
            'valideur' => (int) $this->valideur->getId(),
            'autreValideur' => (int) $this->autreValideur->getId(),
        ];
    }

    protected function tearDown(): void
    {
        $c = $this->connexion;
        $a = $this->ids['activite'];
        $c->executeStatement('DELETE d FROM depenses d JOIN engagements e ON e.id = d.engagement_id WHERE e.activite_id = ?', [$a]);
        $c->executeStatement('DELETE FROM engagements WHERE activite_id = ?', [$a]);
        $c->executeStatement('DELETE FROM dotations WHERE activite_id = ?', [$a]);
        $c->executeStatement('DELETE FROM journal_activite WHERE id > ?', [$this->dernierJournal]);
        $c->executeStatement('DELETE FROM activites WHERE id = ?', [$a]);
        $c->executeStatement('DELETE FROM sous_composantes WHERE id = ?', [$this->ids['sousComposante']]);
        $c->executeStatement('DELETE FROM composantes WHERE id = ?', [$this->ids['composante']]);
        $c->executeStatement('DELETE FROM exercices WHERE id = ?', [$this->ids['exercice']]);
        $c->executeStatement('DELETE FROM projets WHERE id = ?', [$this->ids['projet']]);
        $c->executeStatement('DELETE FROM structures WHERE id = ?', [$this->ids['structure']]);
        $c->executeStatement('DELETE FROM utilisateurs WHERE id IN (?, ?, ?)', [$this->ids['saisie'], $this->ids['valideur'], $this->ids['autreValideur']]);
        parent::tearDown();
    }

    public function testDeuxValidationsSimultaneesNeDepassentPasLaDotation(): void
    {
        $dotation = $this->dotation(100_000);
        $premier = $this->engagement(60_000);
        $second = $this->engagement(60_000);

        $autre = $this->autreValideurTient('dotations', (int) $dotation->getId(), 'engagements', (int) $premier->getId());
        $refus = $this->refusDe(fn () => static::getContainer()->get(EngagementValidationService::class)->valider($second, $this->valideur));
        proc_close($autre);

        self::assertNotNull($refus, 'Le second engagement dépasse la dotation : il doit être refusé.');
        self::assertStringContainsString('au-delà de la dotation', $refus);
        self::assertSame(60_000, (int) $this->connexion->fetchOne("SELECT SUM(montant) FROM engagements WHERE activite_id = ? AND statut = 'valide'", [$this->ids['activite']]));
    }

    public function testDeuxValidationsSimultaneesNeDepassentPasLEngagement(): void
    {
        $engagement = $this->engagement(100_000);
        $engagement->valider($this->valideur);
        $premiere = new Depense($engagement, Montant::fromFcfa(60_000), new \DateTimeImmutable('2026-04-01'), $this->saisie);
        $seconde = new Depense($engagement, Montant::fromFcfa(60_000), new \DateTimeImmutable('2026-04-02'), $this->saisie);
        $this->em->persist($premiere);
        $this->em->persist($seconde);
        $this->em->flush();

        $autre = $this->autreValideurTient('engagements', (int) $engagement->getId(), 'depenses', (int) $premiere->getId());
        $refus = $this->refusDe(fn () => static::getContainer()->get(DepenseValidationService::class)->valider($seconde, $this->valideur));
        proc_close($autre);

        self::assertNotNull($refus, 'La seconde dépense dépasse l\'engagement : elle doit être refusée.');
        self::assertSame(60_000, (int) $this->connexion->fetchOne("SELECT SUM(montant) FROM depenses WHERE engagement_id = ? AND statut = 'valide'", [$engagement->getId()]));
    }

    public function testUneRevisionNePasseSousUnEngagementValideAuMemeMoment(): void
    {
        $dotation = $this->dotation(100_000);
        $engagement = $this->engagement(60_000);

        $autre = $this->autreValideurTient('dotations', (int) $dotation->getId(), 'engagements', (int) $engagement->getId());
        $refus = $this->refusDe(fn () => static::getContainer()->get(DotationRevisionService::class)->reviser($dotation, Montant::fromFcfa(50_000), 'Baisse', null));
        proc_close($autre);

        self::assertNotNull($refus, 'La révision passerait sous ce qui vient d\'être engagé.');
        self::assertSame(100_000, (int) $this->connexion->fetchOne('SELECT montant FROM dotations WHERE id = ?', [$this->ids['dotation']]));
    }

    public function testUnEngagementNeSAnnulePasPendantQuUneDepenseSeValide(): void
    {
        $engagement = $this->engagement(100_000);
        $engagement->valider($this->valideur);
        $enCours = new Depense($engagement, Montant::fromFcfa(10_000), new \DateTimeImmutable('2026-04-02'), $this->saisie);
        $this->em->persist($enCours);
        $this->em->flush();

        // Vue d'avant le verrou, l'engagement ne porte encore aucune dépense
        // vivante : l'autre personne fait passer celle-ci à validée pendant l'attente.
        $this->connexion->executeStatement("UPDATE depenses SET statut = 'annule' WHERE id = ?", [$enCours->getId()]);
        $autre = $this->autreValideurTient('engagements', (int) $engagement->getId(), 'depenses', (int) $enCours->getId());
        $refus = $this->refusDe(fn () => static::getContainer()->get(AnnulationEcritureValideeService::class)->annulerEngagement($engagement, 'Erreur'));
        proc_close($autre);

        self::assertNotNull($refus, 'L\'engagement porte maintenant une dépense validée : il ne s\'annule pas.');
        self::assertSame('valide', $this->connexion->fetchOne('SELECT statut FROM engagements WHERE id = ?', [$engagement->getId()]));
    }

    private function dotation(int $montant): Dotation
    {
        $dotation = new Dotation($this->activite, $this->exercice, Montant::fromFcfa($montant), $this->saisie);
        $this->em->persist($dotation);
        $this->em->flush();
        $this->ids['dotation'] = (int) $dotation->getId();

        return $dotation;
    }

    private function engagement(int $montant): Engagement
    {
        $engagement = new Engagement($this->activite, $this->exercice, NatureEngagement::TRAVAUX, Montant::fromFcfa($montant), new \DateTimeImmutable('2026-03-01'), $this->saisie);
        $this->em->persist($engagement);
        $this->em->flush();

        return $engagement;
    }

    /** Lance l'autre valideur et ne rend la main qu'une fois qu'il tient le verrou. */
    private function autreValideurTient(string $tableVerrou, int $idVerrou, string $table, int $idEcriture): mixed
    {
        $p = $this->connexion->getParams();
        $processus = proc_open(
            [
                \PHP_BINARY,
                __DIR__.'/../Support/tenir_verrou.php',
                (string) ($p['host'] ?? '127.0.0.1'),
                (string) ($p['port'] ?? 3306),
                (string) ($p['dbname'] ?? ''),
                (string) ($p['user'] ?? ''),
                (string) ($p['password'] ?? ''),
                $tableVerrou,
                (string) $idVerrou,
                $table,
                (string) $idEcriture,
                (string) $this->ids['autreValideur'],
            ],
            [1 => ['pipe', 'w']],
            $tuyaux,
        );
        self::assertIsResource($processus);
        self::assertSame("verrou\n", fgets($tuyaux[1]), 'L\'autre valideur doit tenir le verrou avant que le test continue.');

        return $processus;
    }

    private function refusDe(callable $action): ?string
    {
        try {
            $action();

            return null;
        } catch (ContrainteBudgetaireException $e) {
            return $e->getMessage();
        }
    }
}
