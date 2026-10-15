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
 * Critère d'acceptation P5 (§ 10 du brief) : "les alertes se recalculent
 * après une écriture budgétaire, sans rechargement complet." Les alertes
 * n'étant jamais stockées (§ 5.3), il suffit de vérifier que deux appels
 * successifs à /alertes, avec une dépense saisie puis validée entre les
 * deux via l'API (comme dans BudgetChaineHttpTest), donnent deux réponses
 * différentes — sans aucune invalidation de cache à déclencher.
 */
final class AlertesRecalculeesHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLesAlertesSeRecalculentApresUneEcritureBudgetaire(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $activiteUuid = $fixture['activite']->getUuid()->toRfc4122();
            $urlAlertes = \sprintf('/api/v1/projets/%s/alertes?exercice=2026', $fixture['projet']->getUuid()->toRfc4122());

            $this->connecter($client, $fixture['gestionnaire']->getEmail());

            $client->request('POST', '/api/v1/dotations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $activiteUuid,
                'exercice' => $fixture['exercice']->getAnnee(),
                'montant' => 1_000_000,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $client->request('POST', '/api/v1/engagements', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $activiteUuid,
                'exercice' => $fixture['exercice']->getAnnee(),
                'nature' => 'autre_fonctionnement',
                'montant' => 1_000_000,
                'dateEngagement' => '2026-02-01',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $engagementUuid = $this->corps($client)['uuid'];

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/engagements/{$engagementUuid}/valider");
            self::assertResponseIsSuccessful();

            // Exercice civil complet : au 10 août, environ 60 % de l'année
            // est écoulée — un taux d'exécution nul y est donc largement en
            // dessous du seuil de risque élevé (§ 5.3, écart < −0,20).
            $client->request('GET', $urlAlertes);
            self::assertResponseIsSuccessful();
            $avant = $this->corps($client)['alertes'];
            self::assertNotNull(
                $this->trouverParUuidOuNull($avant, $activiteUuid),
                'Sans dépense, le taux d\'exécution nul doit déclencher une alerte de risque élevé.',
            );

            // Écriture budgétaire entre les deux appels : aucune invalidation
            // de cache à faire, la prochaine lecture recalcule à la volée.
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $client->request('POST', '/api/v1/depenses', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'engagement' => $engagementUuid,
                'montant' => 900_000,
                'dateDepense' => '2026-08-01',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $depenseUuid = $this->corps($client)['uuid'];

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/depenses/{$depenseUuid}/valider");
            self::assertResponseIsSuccessful();

            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $client->request('GET', $urlAlertes);
            self::assertResponseIsSuccessful();
            $apres = $this->corps($client)['alertes'];
            self::assertNull(
                $this->trouverParUuidOuNull($apres, $activiteUuid),
                "Après la dépense, le taux d'exécution rejoint le taux attendu : l'alerte doit disparaître.",
            );
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

        $permSaisirEng = new Permission('budget.engagement.saisir', 'budget', 'Saisir un engagement');
        $permValiderEng = new Permission('budget.engagement.valider', 'budget', 'Valider un engagement');
        $permSaisirDep = new Permission('budget.depense.saisir', 'budget', 'Saisir une dépense');
        $permValiderDep = new Permission('budget.depense.valider', 'budget', 'Valider une dépense');
        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        foreach ([$permSaisirEng, $permValiderEng, $permSaisirDep, $permValiderDep, $permConsulter] as $p) {
            $this->em->persist($p);
        }

        $roleGestionnaire = new Role('gestionnaire_budgetaire_alertes', 'Gestionnaire budgétaire', true);
        $roleGestionnaire->accorder($permSaisirEng);
        $roleGestionnaire->accorder($permSaisirDep);
        $roleGestionnaire->accorder($permConsulter);

        $roleCoordonnateur = new Role('coordonnateur_alertes', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permValiderEng);
        $roleCoordonnateur->accorder($permValiderDep);
        $roleCoordonnateur->accorder($permConsulter);

        $this->em->persist($roleGestionnaire);
        $this->em->persist($roleCoordonnateur);

        $structure = new Structure('TST-ALR', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $gestionnaire = new Utilisateur('gestionnaire.alertes@example.test', 'temp', 'Fall', 'Khadija');
        $gestionnaire->setMotDePasseHash($hasher->hashPassword($gestionnaire, self::MOT_DE_PASSE));
        $gestionnaire->marquerMdpChange();
        $this->em->persist($gestionnaire);
        $this->em->persist(new UtilisateurRole($gestionnaire, $roleGestionnaire, null));

        $coordonnateur = new Utilisateur('coordonnateur.alertes@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $coordonnateur->marquerMdpChange();
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $projet = new Projet('TESTALERTES', 'Projet alertes', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);
        $composante = new Composante($projet, 'CA', 'Composante alertes');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CA.1', 'Sous-composante alertes');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CA.1.1', 'Activité alertes', $structure);
        $this->em->persist($activite);

        $this->em->flush();

        return [$client, [
            'gestionnaire' => $gestionnaire,
            'coordonnateur' => $coordonnateur,
            'projet' => $projet,
            'exercice' => $exercice,
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

    /**
     * @param list<array<string, mixed>> $alertes
     *
     * @return array<string, mixed>|null
     */
    private function trouverParUuidOuNull(array $alertes, string $uuid): ?array
    {
        foreach ($alertes as $alerte) {
            if ($alerte['uuid'] === $uuid) {
                return $alerte;
            }
        }

        return null;
    }

    private function rollback(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
    }
}
