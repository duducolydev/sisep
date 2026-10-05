<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Dotation;
use App\Entity\Exercice;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\TypeStructure;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * § 6.2, § 10 du brief (P7). Critère d'acceptation : "le rapport trimestriel
 * s'exporte en PDF" — ce test vérifie le format (en-tête %PDF), pas
 * seulement le code HTTP, pour prouver un document réellement exploitable.
 */
final class ExportHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testExportBudgetXlsx(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('GET', '/api/v1/exports/budget.xlsx?exercice=2026');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));

            // Un .xlsx est une archive Zip : signature "PK".
            $corps = (string) $client->getResponse()->getContent();
            self::assertStringStartsWith('PK', $corps);
            self::assertGreaterThan(1000, \strlen($corps), 'Le classeur généré ne doit pas être vide.');
        } finally {
            $this->rollback();
        }
    }

    public function testExportCadreResultatsXlsx(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('GET', '/api/v1/exports/cadre-resultats.xlsx?exercice=2026');
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('PK', (string) $client->getResponse()->getContent());
        } finally {
            $this->rollback();
        }
    }

    public function testExportRapportTrimestrielPdf(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('GET', '/api/v1/exports/rapport-trimestriel.pdf?exercice=2026&periode=T3');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/pdf');

            $corps = (string) $client->getResponse()->getContent();
            self::assertStringStartsWith('%PDF', $corps, 'Le document généré doit être un PDF valide.');
        } finally {
            $this->rollback();
        }
    }

    public function testUnParametrePeriodeInvalideEstRefuse(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('GET', '/api/v1/exports/rapport-trimestriel.pdf?exercice=2026&periode=T9');
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    public function testUnUtilisateurSansExportsGenererNePeutPasExporter(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['lecteur']->getEmail());

            $client->request('GET', '/api/v1/exports/budget.xlsx?exercice=2026');
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

        $permExports = new Permission('exports.generer', 'exports', 'Générer un export');
        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $this->em->persist($permExports);
        $this->em->persist($permConsulter);

        $roleCoordonnateur = new Role('coordonnateur_export_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permExports);
        $roleCoordonnateur->accorder($permConsulter);
        $this->em->persist($roleCoordonnateur);

        $roleLecteur = new Role('lecteur_export_test', 'Lecteur', true);
        $roleLecteur->accorder($permConsulter);
        $this->em->persist($roleLecteur);

        $structure = new Structure('TST-EXP', 'Structure de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $coordonnateur = new Utilisateur('coordonnateur.export@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $lecteur = new Utilisateur('lecteur.export@example.test', 'temp', 'Diop', 'Cheikh');
        $lecteur->setMotDePasseHash($hasher->hashPassword($lecteur, self::MOT_DE_PASSE));
        $this->em->persist($lecteur);
        $this->em->persist(new UtilisateurRole($lecteur, $roleLecteur, null));

        $projet = new Projet('TESTEXPORT', 'Projet export', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);
        $composante = new Composante($projet, 'CE', 'Composante export');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CE.1', 'Sous-composante export');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CE.1.1', 'Activité export', $structure);
        $this->em->persist($activite);
        $this->em->persist(new Dotation($activite, $exercice, Montant::fromFcfa(1_000_000), $coordonnateur));

        $this->em->flush();

        return [$client, [
            'coordonnateur' => $coordonnateur,
            'lecteur' => $lecteur,
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

    private function rollback(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
    }
}
