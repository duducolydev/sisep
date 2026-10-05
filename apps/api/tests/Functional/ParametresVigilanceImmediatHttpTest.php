<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\Exercice;
use App\Entity\Parametre;
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
 * Critère d'acceptation P6 (§ 10 du brief) : "modifier un seuil de vigilance
 * change immédiatement les statuts affichés." VigilanceCalculator relit le
 * paramètre à chaque appel (§ 5.3, P5) ; ce test prouve que PUT
 * /admin/parametres suffit, sans purge de cache ni redémarrage.
 */
final class ParametresVigilanceImmediatHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testModifierLeSeuilDeVigilanceChangeImmediatementLesAlertes(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $activiteUuid = $fixture['activite']->getUuid()->toRfc4122();
            $urlAlertes = \sprintf('/api/v1/projets/%s/alertes?exercice=2026', $fixture['projet']->getUuid()->toRfc4122());

            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            // Aucune dépense : taux d'exécution nul, écart ≈ −0,60 (exercice
            // civil complet, ~60 % de l'année écoulée) → sous le seuil par
            // défaut (−0,20), l'activité est en risque élevé.
            $client->request('GET', $urlAlertes);
            self::assertResponseIsSuccessful();
            self::assertNotNull(
                $this->trouverParUuidOuNull($this->corps($client)['alertes'], $activiteUuid),
                'Avec le seuil par défaut (−0,20), un écart de −0,60 doit déclencher une alerte.',
            );

            // Assouplissement du seuil par la coordination : aucune écriture
            // budgétaire, seulement le paramètre.
            $client->request('PUT', '/api/v1/admin/parametres', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'vigilance.seuil_risque' => '-0.99',
                'vigilance.seuil_retard' => '-0.99',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('GET', $urlAlertes);
            self::assertResponseIsSuccessful();
            self::assertNull(
                $this->trouverParUuidOuNull($this->corps($client)['alertes'], $activiteUuid),
                'Seuil assoupli à −0,99 : le même écart de −0,60 ne doit plus déclencher d\'alerte, immédiatement.',
            );
        } finally {
            $this->rollback();
        }
    }

    public function testUnUtilisateurSansAdminParametresNePeutPasModifierLesSeuils(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['lecteur']->getEmail());

            $client->request('PUT', '/api/v1/admin/parametres', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'vigilance.seuil_risque' => '-0.99',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(403);
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
        $permAdminParametres = new Permission('admin.parametres', 'admin', 'Gérer les paramètres');
        $this->em->persist($permConsulter);
        $this->em->persist($permAdminParametres);

        $roleCoordonnateur = new Role('coordonnateur_param', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permConsulter);
        $roleCoordonnateur->accorder($permAdminParametres);
        $this->em->persist($roleCoordonnateur);

        $roleLecteur = new Role('lecteur_param', 'Lecteur', true);
        $roleLecteur->accorder($permConsulter);
        $this->em->persist($roleLecteur);

        $structure = new Structure('TST-PAR', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $coordonnateur = new Utilisateur('coordonnateur.param@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $lecteur = new Utilisateur('lecteur.param@example.test', 'temp', 'Diop', 'Cheikh');
        $lecteur->setMotDePasseHash($hasher->hashPassword($lecteur, self::MOT_DE_PASSE));
        $this->em->persist($lecteur);
        $this->em->persist(new UtilisateurRole($lecteur, $roleLecteur, null));

        $this->em->persist(new Parametre('vigilance.seuil_retard', '-0.10', 'Seuil de retard'));
        $this->em->persist(new Parametre('vigilance.seuil_risque', '-0.20', 'Seuil de risque élevé'));

        $projet = new Projet('TESTPARAM', 'Projet paramètres', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);
        $composante = new Composante($projet, 'CP', 'Composante paramètres');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CP.1', 'Sous-composante paramètres');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CP.1.1', 'Activité paramètres', $structure);
        $this->em->persist($activite);

        // Notifié + engagé, mais aucune dépense : taux d'exécution nul.
        $this->em->persist(new Dotation($activite, $exercice, Montant::fromFcfa(1_000_000), $coordonnateur));
        $engagement = new Engagement($activite, $exercice, NatureEngagement::FONCTIONNEMENT, Montant::fromFcfa(1_000_000), new \DateTimeImmutable('2026-02-01'), $coordonnateur);
        $engagement->valider($coordonnateur);
        $this->em->persist($engagement);

        $this->em->flush();

        return [$client, [
            'coordonnateur' => $coordonnateur,
            'lecteur' => $lecteur,
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
