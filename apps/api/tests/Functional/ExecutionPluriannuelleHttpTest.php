<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\Exercice;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
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
 * GET /projets/:uuid/execution-pluriannuelle — contrairement à
 * /decaissement (un seul exercice), agrège tout le projet sur tous les
 * exercices : un total par année plus un cumul global, jamais stocké,
 * division par zéro → null (§ 5.1).
 */
final class ExecutionPluriannuelleHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testAgregeLesMontantsParExerciceEtEnCumulGlobal(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['utilisateur']->getEmail());

            $client->request('GET', \sprintf('/api/v1/projets/%s/execution-pluriannuelle', $fixture['projet']->getUuid()->toRfc4122()));
            self::assertResponseIsSuccessful();
            $corps = $this->corps($client);

            $parAnnee = [];
            foreach ($corps['annees'] as $ligne) {
                $parAnnee[$ligne['annee']] = $ligne;
            }

            self::assertSame(10_000_000, $parAnnee[2025]['notifie']);
            self::assertSame(8_000_000, $parAnnee[2025]['depense']);
            self::assertSame(2_000_000, $parAnnee[2025]['disponible']);
            self::assertEqualsWithDelta(0.8, $parAnnee[2025]['tauxExecution'], 0.0001);

            self::assertSame(5_000_000, $parAnnee[2027]['notifie']);
            self::assertSame(0, $parAnnee[2027]['depense']);
            // json_encode ne préserve pas ".0" sur un float rond (0.0 devient
            // "0" en JSON, redécodé en int côté test) — sans conséquence pour
            // le front (JS ne distingue pas 0 de 0.0), d'où assertEqualsWithDelta
            // plutôt qu'assertSame ici.
            self::assertEqualsWithDelta(0.0, $parAnnee[2027]['tauxExecution'], 0.0001, 'Dotation notifiée mais rien dépensé : taux = 0.0, pas null (null est réservé à la division par zéro, notifie=0).');
        } finally {
            $this->rollback();
        }
    }

    public function testUneAnneeSansAucuneDotationNaPasDeTauxDefiniAlorsQueLeGlobalCumuleTout(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['utilisateur']->getEmail());

            $client->request('GET', \sprintf('/api/v1/projets/%s/execution-pluriannuelle', $fixture['projet']->getUuid()->toRfc4122()));
            self::assertResponseIsSuccessful();
            $corps = $this->corps($client);

            $annees = array_column($corps['annees'], 'annee');
            self::assertNotContains(2029, $annees, 'Un exercice sans dotation ni dépense ne doit pas apparaître (rien à agréger).');

            self::assertSame(15_000_000, $corps['global']['notifie']);
            self::assertSame(8_000_000, $corps['global']['depense']);
            self::assertSame(7_000_000, $corps['global']['disponible']);
            self::assertEqualsWithDelta(8_000_000 / 15_000_000, $corps['global']['tauxExecution'], 0.0001);
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

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $this->em->persist($permConsulter);
        $role = new Role('lecteur_execution_pluriannuelle_test', 'Lecteur', true);
        $role->accorder($permConsulter);
        $this->em->persist($role);

        $structure = new Structure('TST-EP', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $utilisateur = new Utilisateur('lecteur.execution.pluriannuelle@example.test', 'temp', 'Diop', 'Cheikh');
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));
        $utilisateur->marquerMdpChange();
        $this->em->persist($utilisateur);
        $this->em->persist(new UtilisateurRole($utilisateur, $role, null));

        $projet = new Projet('TESTEXECPLURI', 'Projet exécution pluriannuelle', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, 'CEP', 'Composante exécution pluriannuelle');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CEP.1', 'Sous-composante exécution pluriannuelle');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CEP.1.1', 'Activité exécution pluriannuelle', $structure);
        $this->em->persist($activite);

        $exercice2025 = new Exercice($projet, 2025, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'));
        $exercice2027 = new Exercice($projet, 2027, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31'));
        $exercice2029 = new Exercice($projet, 2029, new \DateTimeImmutable('2029-01-01'), new \DateTimeImmutable('2029-12-31'));
        $this->em->persist($exercice2025);
        $this->em->persist($exercice2027);
        $this->em->persist($exercice2029);

        // 2025 : dotation notifiée + dépense validée (80 % exécuté).
        $dotation2025 = new Dotation($activite, $exercice2025, Montant::fromFcfa(10_000_000), $utilisateur);
        $this->em->persist($dotation2025);
        $engagement2025 = new Engagement($activite, $exercice2025, NatureEngagement::SERVICE_CONSULTATION, Montant::fromFcfa(10_000_000), new \DateTimeImmutable('2025-03-01'), $utilisateur);
        $engagement2025->valider($utilisateur);
        $this->em->persist($engagement2025);
        $depense2025 = new Depense($engagement2025, Montant::fromFcfa(8_000_000), new \DateTimeImmutable('2025-11-01'), $utilisateur);
        $depense2025->valider($utilisateur);
        $this->em->persist($depense2025);

        // 2027 : dotation seule, rien engagé ni dépensé.
        $dotation2027 = new Dotation($activite, $exercice2027, Montant::fromFcfa(5_000_000), $utilisateur);
        $this->em->persist($dotation2027);

        // 2029 : exercice existant, mais sans aucune dotation/dépense — ne doit pas apparaître.

        $this->em->flush();

        return [$client, [
            'utilisateur' => $utilisateur,
            'projet' => $projet,
        ]];
    }

    private function connecter(KernelBrowser $client, string $email): void
    {
        $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'motDePasse' => self::MOT_DE_PASSE,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
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
}
