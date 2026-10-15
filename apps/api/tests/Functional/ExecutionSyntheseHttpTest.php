<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\EtapeActivite;
use App\Entity\Exercice;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\NatureEngagement;
use App\Enum\TypeStructure;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tableau de bord d'exécution physique et financière (retours des RSE,
 * octobre 2026) : unités réalisées sur le total, budget/engagé/dépensé à
 * fin de période, filtres par période et par composante ou activité.
 */
final class ExecutionSyntheseHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testExecutionPhysiqueEtFinanciereSurLExerciceEtSurUnTrimestre(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $url = \sprintf('/api/v1/projets/%s/execution?exercice=2026', $f['projet']->getUuid()->toRfc4122());

            // Exercice entier : RNOCB réalisée, ENES à moitié, l'activité
            // sans ligne non structurée — 1 réalisée sur 3 unités.
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $totaux = $this->corps($client)['totaux'];
            self::assertSame(3, $totaux['unites']);
            self::assertSame(1, $totaux['realisees']);
            self::assertEqualsWithDelta(1 / 3, $totaux['tauxPhysique'], 0.0001);
            self::assertEqualsWithDelta(0.75, $totaux['avancementMoyen'], 0.0001, 'Moyenne des unités structurées : (100 % + 50 %) / 2.');
            self::assertSame(1_000_000, $totaux['budget']);
            self::assertSame(600_000, $totaux['engage']);
            self::assertSame(300_000, $totaux['depense']);
            self::assertEqualsWithDelta(0.3, $totaux['tauxExecution'], 0.0001);
            self::assertEqualsWithDelta(0.5, $totaux['tauxDecaissement'], 0.0001);
            self::assertSame('composante', $this->corps($client)['niveau']);

            // T1 : l'engagement de mars compte, la dépense de mai pas encore.
            $client->request('GET', $url.'&trimestre=1');
            $t1 = $this->corps($client);
            self::assertSame(['debut' => '2026-01-01', 'fin' => '2026-03-31'], $t1['periode']);
            self::assertSame(600_000, $t1['totaux']['engage']);
            self::assertSame(0, $t1['totaux']['depense']);
            self::assertSame(1, $t1['totaux']['realisees'], 'RNOCB, achevée en février, est réalisée à fin mars.');

            // Janvier : RNOCB n'était pas encore achevée.
            $client->request('GET', $url.'&mois=1');
            self::assertSame(0, $this->corps($client)['totaux']['realisees']);

            // Filtre activité : le détail descend aux lignes.
            $client->request('GET', $url.'&activite='.$f['activiteLignes']->getUuid()->toRfc4122());
            $detail = $this->corps($client);
            self::assertSame('ligne', $detail['niveau']);
            self::assertSame(['RNOCB', 'ENES'], array_column($detail['lignes'], 'libelle'));
            self::assertSame(2, $detail['totaux']['unites']);

            // Filtre composante : le détail descend aux activités.
            $client->request('GET', $url.'&composante='.$f['composante']->getUuid()->toRfc4122());
            self::assertSame('activite', $this->corps($client)['niveau']);
            self::assertCount(2, $this->corps($client)['lignes']);

            // Recherche directe d'une ligne par son nom : la liste des lignes
            // alimente la recherche, le filtre ne garde que la ligne choisie.
            self::assertSame(['RNOCB', 'ENES'], array_column($t1['options']['lignes'], 'libelle'));
            $client->request('GET', $url.'&ligne='.$f['rnocb']->getUuid()->toRfc4122());
            $ligne = $this->corps($client);
            self::assertSame('ligne', $ligne['niveau']);
            self::assertSame(['RNOCB'], array_column($ligne['lignes'], 'libelle'));
            self::assertSame(1_000_000, $ligne['totaux']['budget']);
            self::assertSame(1, $ligne['totaux']['realisees']);

            $client->request('GET', $url.'&trimestre=5');
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    /** @return array{0: KernelBrowser, 1: array<string, mixed>} */
    private function demarrer(): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $consulter = new Permission('activites.consulter', 'activites', 'Consulter');
        $this->em->persist($consulter);
        $role = new Role('lecteur_execution_test', 'Lecteur', true);
        $role->accorder($consulter);
        $this->em->persist($role);

        $structure = new Structure('TST-EXE', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $utilisateur = new Utilisateur('lecteur.execution@example.test', 'temp', 'Test', 'Lecteur');
        $utilisateur->setMotDePasseHash($container->get(UserPasswordHasherInterface::class)->hashPassword($utilisateur, self::MOT_DE_PASSE));
        $utilisateur->marquerMdpChange();
        $this->em->persist($utilisateur);
        $this->em->persist(new UtilisateurRole($utilisateur, $role));

        $projet = new Projet('TESTEXE', 'Projet exécution', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);
        $composante = new Composante($projet, 'CE', 'Composante exécution');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CE.1', 'Sous-composante exécution');
        $this->em->persist($sousComposante);

        $activiteLignes = new Activite($sousComposante, 'CE.1.1', 'Statistiques administratives', $structure);
        $this->em->persist($activiteLignes);
        $activiteSeule = new Activite($sousComposante, 'CE.1.2', 'Activité sans ligne', $structure);
        $this->em->persist($activiteSeule);

        $rnocb = new SousActivite($activiteLignes, 'RNOCB');
        $rnocb->setOrdre(1);
        $this->em->persist($rnocb);
        $enes = new SousActivite($activiteLignes, 'ENES');
        $enes->setOrdre(2);
        $this->em->persist($enes);

        foreach (['Recrutement', 'Dénombrement'] as $i => $libelle) {
            $etape = new EtapeActivite($activiteLignes, $libelle, $i + 1, $rnocb);
            $etape->renseigner(100, null, null, new \DateTimeImmutable('2026-02-10'));
            $this->em->persist($etape);
        }
        $etapeEnes = new EtapeActivite($activiteLignes, 'Collecte', 1, $enes);
        $etapeEnes->renseigner(50, null, null, new \DateTimeImmutable('2026-04-15'));
        $this->em->persist($etapeEnes);

        $this->em->persist(new Dotation($rnocb, $exercice, Montant::fromFcfa(1_000_000), $utilisateur));
        $engagement = new Engagement($rnocb, $exercice, NatureEngagement::SERVICE_CONSULTATION, Montant::fromFcfa(600_000), new \DateTimeImmutable('2026-03-01'), $utilisateur);
        $engagement->valider($utilisateur);
        $this->em->persist($engagement);
        $depense = new Depense($engagement, Montant::fromFcfa(300_000), new \DateTimeImmutable('2026-05-01'), $utilisateur);
        $depense->valider($utilisateur);
        $this->em->persist($depense);
        // Brouillon : n'entre dans aucun total.
        $this->em->persist(new Depense($engagement, Montant::fromFcfa(100_000), new \DateTimeImmutable('2026-05-02'), $utilisateur));

        $this->em->flush();

        $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'lecteur.execution@example.test',
            'motDePasse' => self::MOT_DE_PASSE,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return [$client, [
            'projet' => $projet,
            'composante' => $composante,
            'activiteLignes' => $activiteLignes,
            'rnocb' => $rnocb,
        ]];
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
