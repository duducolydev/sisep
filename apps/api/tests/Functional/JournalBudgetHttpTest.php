<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Exercice;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vue budgétaire du journal d'activité — contrepartie de la dérogation qui
 * laisse le responsable suivi-évaluation saisir et valider les mêmes
 * écritures (§ 7.2 du brief, décision du 22/09/2026).
 *
 * Deux propriétés à verrouiller : la vue ne montre que des écritures
 * budgétaires, et elle les rend lisibles — l'activité, l'exercice et le
 * montant, pas seulement un type et un identifiant.
 */
final class JournalBudgetHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLeFilBudgetaireNeMontreQueLesEcrituresBudgetaires(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['gestionnaire']->getEmail());

            // Une écriture budgétaire…
            $client->request('POST', '/api/v1/dotations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => 2026,
                'montant' => 4_680_000,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            // …et une écriture non budgétaire, qui ne doit pas s'y retrouver.
            $client->request('PATCH', \sprintf('/api/v1/activites/%s', $fixture['activite']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'libelle' => 'Activité renommée',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            // La lecture relève de l'auditeur : celui qui saisit n'a pas à
            // détenir le droit de relire le fil (cf. test suivant).
            $this->connecter($client, $fixture['auditeur']->getEmail());
            $client->request('GET', '/api/v1/journal/budget');
            self::assertResponseIsSuccessful();

            $entrees = $this->corps($client)['donnees'];
            self::assertNotEmpty($entrees);
            foreach ($entrees as $entree) {
                self::assertContains(
                    $entree['entiteType'],
                    ['dotation', 'engagement', 'depense'],
                    'La vue budgétaire ne doit pas ouvrir sur les écritures de droits ou de comptes.',
                );
            }

            $dotation = $entrees[0];
            self::assertSame('creation', $dotation['action']);
            self::assertSame('Khadija Fall', $dotation['utilisateur'], "Le fil nomme l'auteur de l'écriture, pas celui qui le consulte.");
            self::assertSame('CJB.1.1', $dotation['reference']['activite']['code'], "L'entrée doit dire sur quelle activité porte l'écriture.");
            self::assertSame(2026, $dotation['reference']['exercice']);
            self::assertSame('4680000', $dotation['reference']['montant'], 'Le montant voyage en chaîne, pas en nombre JSON.');

            // Filtre par type : les dépenses seules, donc aucune ici.
            $client->request('GET', '/api/v1/journal/budget?type=depense');
            self::assertCount(0, $this->corps($client)['donnees']);
        } finally {
            $this->rollback();
        }
    }

    public function testLaVueBudgetaireExigeLeDroitDAudit(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            // Le gestionnaire budgétaire saisit, mais ne consulte pas le
            // journal : lire qui a validé quoi est un droit d'audit distinct.
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $client->request('GET', '/api/v1/journal/budget');
            self::assertResponseStatusCodeSame(403);

            $this->connecter($client, $fixture['auditeur']->getEmail());
            $client->request('GET', '/api/v1/journal/budget');
            self::assertResponseIsSuccessful();
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
        $permModifier = new Permission('activites.modifier', 'activites', 'Modifier une activité');
        $permSaisir = new Permission('budget.engagement.saisir', 'budget', 'Saisir un engagement');
        $permJournal = new Permission('journal.consulter', 'journal', "Consulter le journal d'activité");
        foreach ([$permConsulter, $permModifier, $permSaisir, $permJournal] as $p) {
            $this->em->persist($p);
        }

        $roleGestionnaire = new Role('gestionnaire_journal_test', 'Gestionnaire budgétaire', true);
        $roleGestionnaire->accorder($permConsulter);
        $roleGestionnaire->accorder($permModifier);
        $roleGestionnaire->accorder($permSaisir);
        $this->em->persist($roleGestionnaire);

        $roleAuditeur = new Role('auditeur_journal_test', 'Auditeur', true);
        $roleAuditeur->accorder($permConsulter);
        $roleAuditeur->accorder($permJournal);
        $this->em->persist($roleAuditeur);

        $structure = new Structure('TST-JB', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $gestionnaire = new Utilisateur('gestionnaire.journal@example.test', 'temp', 'Fall', 'Khadija');
        $gestionnaire->setMotDePasseHash($hasher->hashPassword($gestionnaire, self::MOT_DE_PASSE));
        $this->em->persist($gestionnaire);
        $this->em->persist(new UtilisateurRole($gestionnaire, $roleGestionnaire, null));

        $auditeur = new Utilisateur('auditeur.journal@example.test', 'temp', 'Ndiaye', 'Moussa');
        $auditeur->setMotDePasseHash($hasher->hashPassword($auditeur, self::MOT_DE_PASSE));
        $this->em->persist($auditeur);
        $this->em->persist(new UtilisateurRole($auditeur, $roleAuditeur, null));

        $projet = new Projet('TESTJB', 'Projet journal budget', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $this->em->persist(new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        $composante = new Composante($projet, 'CJB', 'Composante journal budget');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CJB.1', 'Sous-composante journal budget');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CJB.1.1', 'Activité journal budget', $structure);
        $this->em->persist($activite);

        $this->em->flush();

        return [$client, [
            'gestionnaire' => $gestionnaire,
            'auditeur' => $auditeur,
            'activite' => $activite,
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

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }
}
