<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
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
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * § 6.2 du brief (P7) : /arbre est mis en cache côté serveur, "avec
 * invalidation sur toute écriture budgétaire" — sans quoi une dotation
 * saisie n'apparaîtrait pas avant l'expiration du cache (une heure,
 * ArbreReader). Ce test échouerait si ArbreCacheInvalidationListener ne
 * purgeait pas le cache après la création de la dotation.
 */
final class ArbreCacheHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLarbreRefleteUneDotationSaisieApresUnPremierAppelMisEnCache(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $projetUuid = $fixture['projet']->getUuid()->toRfc4122();
            $composanteUuid = $fixture['composante']->getUuid()->toRfc4122();

            // Premier appel : peuple le cache avec un notifié à 0.
            $client->request('GET', "/api/v1/projets/{$projetUuid}/arbre?exercice=2026&niveaux=3");
            self::assertResponseIsSuccessful();
            $avant = $this->trouverComposante($this->corps($client)['composantes'], $composanteUuid);
            self::assertSame(0, $avant['notifie'] ?? 0);

            // Écriture budgétaire : doit purger le cache /arbre.
            $client->request('POST', '/api/v1/dotations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $fixture['activite']->getUuid()->toRfc4122(),
                'exercice' => 2026,
                'montant' => 750_000,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            // Deuxième appel : doit refléter la dotation, pas la réponse mise en cache.
            $client->request('GET', "/api/v1/projets/{$projetUuid}/arbre?exercice=2026&niveaux=3");
            self::assertResponseIsSuccessful();
            $apres = $this->trouverComposante($this->corps($client)['composantes'], $composanteUuid);
            self::assertSame(
                750_000,
                $apres['notifie'] ?? null,
                "Le cache de l'arbre doit être invalidé après une écriture budgétaire (§ 6.2 du brief).",
            );
        } finally {
            $this->rollback();
        }
    }

    /**
     * Régression : un avancement saisi n'invalidait pas le cache /arbre,
     * alors que l'arbre affiche la progression d'une activité, désormais
     * déduite de ses étapes (§ 6.2, § 4.6, § 6 du CR du 22/09/2026).
     */
    public function testLarbreRefleteUnAvancementSaisiApresUnPremierAppelMisEnCache(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $projetUuid = $fixture['projet']->getUuid()->toRfc4122();
            $activiteUuid = $fixture['activite']->getUuid()->toRfc4122();
            $etapeUuid = $fixture['etape']->getUuid()->toRfc4122();

            // Premier appel : peuple le cache avec un avancement à 0.
            $client->request('GET', "/api/v1/projets/{$projetUuid}/arbre?exercice=2026&niveaux=4");
            self::assertResponseIsSuccessful();
            $avant = $this->trouverActivite($this->corps($client)['composantes'], $activiteUuid);
            self::assertSame(0, $avant['avancement'] ?? null);

            // Renseignement d'une étape : doit purger le cache /arbre.
            $client->request(
                'PATCH',
                "/api/v1/etapes/{$etapeUuid}",
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['avancement' => 40, 'dateMiseAJour' => '2026-06-01'], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseIsSuccessful();

            // Deuxième appel : doit refléter le nouvel avancement, pas la réponse mise en cache.
            $client->request('GET', "/api/v1/projets/{$projetUuid}/arbre?exercice=2026&niveaux=4");
            self::assertResponseIsSuccessful();
            $apres = $this->trouverActivite($this->corps($client)['composantes'], $activiteUuid);
            self::assertSame(
                40,
                $apres['avancement'] ?? null,
                "Le cache de l'arbre doit être invalidé après le renseignement d'une étape (§ 6.2 du brief).",
            );
        } finally {
            $this->rollback();
        }
    }

    /**
     * Régression : le statut d'une sous-activité (§ 4.2 — cycle de vie
     * saisi, distinct de l'avancement) n'invalidait pas le cache /arbre,
     * puisqu'il ne passe pas par une Realisation. Constaté en usage réel :
     * clôturer une sous-activité (activites.valider) restait invisible dans
     * l'arbre jusqu'à expiration du cache.
     */
    public function testLarbreRefleteUnChangementDeStatutApresUnPremierAppelMisEnCache(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['gestionnaire']->getEmail());
            $projetUuid = $fixture['projet']->getUuid()->toRfc4122();
            $activiteUuid = $fixture['activite']->getUuid()->toRfc4122();
            $sousActiviteUuid = $fixture['sousActivite']->getUuid()->toRfc4122();

            // Premier appel : peuple le cache avec le statut initial.
            $client->request('GET', "/api/v1/projets/{$projetUuid}/arbre?exercice=2026&niveaux=4");
            self::assertResponseIsSuccessful();
            $avant = $this->trouverSousActivite($this->corps($client)['composantes'], $activiteUuid, $sousActiviteUuid);
            self::assertSame('planifiee', $avant['statut'] ?? null);

            // Changement de statut (hors Realisation) : doit purger le cache /arbre.
            $client->request('PATCH', "/api/v1/sous-activites/{$sousActiviteUuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'statut' => 'en_cours',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            // Deuxième appel : doit refléter le nouveau statut, pas la réponse mise en cache.
            $client->request('GET', "/api/v1/projets/{$projetUuid}/arbre?exercice=2026&niveaux=4");
            self::assertResponseIsSuccessful();
            $apres = $this->trouverSousActivite($this->corps($client)['composantes'], $activiteUuid, $sousActiviteUuid);
            self::assertSame(
                'en_cours',
                $apres['statut'] ?? null,
                "Le cache de l'arbre doit être invalidé après un changement de statut (§ 6.2, § 4.2 du brief).",
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

        $permSaisir = new Permission('budget.engagement.saisir', 'budget', 'Saisir un engagement');
        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $permModifier = new Permission('activites.modifier', 'activites', 'Modifier une activité');
        $this->em->persist($permSaisir);
        $this->em->persist($permConsulter);
        $this->em->persist($permModifier);

        $roleGestionnaire = new Role('gestionnaire_cache_test', 'Gestionnaire budgétaire', true);
        $roleGestionnaire->accorder($permSaisir);
        $roleGestionnaire->accorder($permConsulter);
        $roleGestionnaire->accorder($permModifier);
        $this->em->persist($roleGestionnaire);

        $structure = new Structure('TST-CACHE', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $gestionnaire = new Utilisateur('gestionnaire.cache@example.test', 'temp', 'Fall', 'Khadija');
        $gestionnaire->setMotDePasseHash($hasher->hashPassword($gestionnaire, self::MOT_DE_PASSE));
        $gestionnaire->marquerMdpChange();
        $this->em->persist($gestionnaire);
        $this->em->persist(new UtilisateurRole($gestionnaire, $roleGestionnaire, null));

        $projet = new Projet('TESTCACHE', 'Projet cache', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);
        $composante = new Composante($projet, 'CC', 'Composante cache');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CC.1', 'Sous-composante cache');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CC.1.1', 'Activité cache', $structure);
        $this->em->persist($activite);
        $sousActivite = new SousActivite($activite, 'Sous-activité cache');
        $this->em->persist($sousActivite);
        $etape = new EtapeActivite($activite, 'Étape cache', 1);
        $this->em->persist($etape);

        $this->em->flush();

        return [$client, [
            'gestionnaire' => $gestionnaire,
            'projet' => $projet,
            'composante' => $composante,
            'activite' => $activite,
            'sousActivite' => $sousActivite,
            'etape' => $etape,
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
     * @param list<array<string, mixed>> $composantes
     *
     * @return array<string, mixed>
     */
    private function trouverComposante(array $composantes, string $uuid): array
    {
        foreach ($composantes as $c) {
            if ($c['uuid'] === $uuid) {
                return $c;
            }
        }

        self::fail("Composante {$uuid} introuvable dans la réponse.");
    }

    /**
     * @param list<array<string, mixed>> $composantes
     *
     * @return array<string, mixed>
     */
    private function trouverActivite(array $composantes, string $activiteUuid): array
    {
        foreach ($composantes as $c) {
            foreach ($c['sousComposantes'] ?? [] as $sc) {
                foreach ($sc['activites'] ?? [] as $a) {
                    if ($a['uuid'] === $activiteUuid) {
                        return $a;
                    }
                }
            }
        }

        self::fail("Activité {$activiteUuid} introuvable dans la réponse.");
    }

    /**
     * @param list<array<string, mixed>> $composantes
     *
     * @return array<string, mixed>
     */
    private function trouverSousActivite(array $composantes, string $activiteUuid, string $sousActiviteUuid): array
    {
        foreach ($composantes as $c) {
            foreach ($c['sousComposantes'] ?? [] as $sc) {
                foreach ($sc['activites'] ?? [] as $a) {
                    if ($a['uuid'] !== $activiteUuid) {
                        continue;
                    }

                    foreach ($a['sousActivites'] ?? [] as $sa) {
                        if ($sa['uuid'] === $sousActiviteUuid) {
                            return $sa;
                        }
                    }
                }
            }
        }

        self::fail("Sous-activité {$sousActiviteUuid} introuvable dans la réponse.");
    }

    private function rollback(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
    }
}
