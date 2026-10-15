<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * § 4.7 du brief : une jointure directe activités → engagements → dépenses
 * avec deux SUM() multiplierait le montant d'engagement par le nombre de
 * dépenses associées. "C'est le bug qui passe inaperçu le plus longtemps" —
 * ce test vérifie qu'une activité avec 1 engagement et 3 dépenses affiche
 * bien le montant engagé une seule fois dans v_execution_activite.
 */
final class ExecutionActiviteViewTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testUnEngagementAvecTroisDepensesNestPasMultiplie(): void
    {
        $projet = new Projet('TEST', 'Projet de test', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);

        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);

        $structure = new Structure('TST', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $utilisateur = new Utilisateur('test.piege@sisep.local', 'hash', 'Test', 'Piège');
        $this->em->persist($utilisateur);

        $composante = new Composante($projet, 'CT', 'Composante de test');
        $this->em->persist($composante);

        $sousComposante = new SousComposante($composante, 'CT.1', 'Sous-composante de test');
        $this->em->persist($sousComposante);

        $activite = new Activite($sousComposante, 'CT.1.1', 'Activité de test', $structure);
        $this->em->persist($activite);

        $dotation = new Dotation($activite, $exercice, Montant::fromFcfa(1_000_000), $utilisateur);
        $this->em->persist($dotation);

        $engagement = new Engagement(
            $activite,
            $exercice,
            NatureEngagement::AUTRE_FONCTIONNEMENT,
            Montant::fromFcfa(600_000),
            new \DateTimeImmutable('2026-03-01'),
            $utilisateur,
        );
        $engagement->valider($utilisateur);
        $this->em->persist($engagement);

        foreach ([100_000, 150_000, 200_000] as $montant) {
            $depense = new Depense($engagement, Montant::fromFcfa($montant), new \DateTimeImmutable('2026-04-01'), $utilisateur);
            $depense->valider($utilisateur);
            $this->em->persist($depense);
        }

        $this->em->flush();

        $ligne = $this->em->getConnection()->fetchAssociative(
            'SELECT notifie, engage, depense FROM v_execution_activite WHERE activite_id = ?',
            [$activite->getId()],
        );

        self::assertNotFalse($ligne, "La vue n'a retourné aucune ligne pour l'activité de test.");
        self::assertSame(1_000_000, (int) $ligne['notifie']);
        self::assertSame(600_000, (int) $ligne['engage'], 'Le montant engagé ne doit pas être multiplié par le nombre de dépenses.');
        self::assertSame(450_000, (int) $ligne['depense'], 'Les trois dépenses doivent être sommées une seule fois chacune.');
    }
}
