<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Permission;
use App\Entity\PieceJointe;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Bibliothèque documentaire de l'UGP (§ 12 du CR du 22/09/2026).
 *
 * Les tests verrouillent la séparation des deux droits : déposer et retirer
 * relèvent de l'UGP (documents.gerer), consulter relève de la visibilité du
 * document lui-même.
 */
final class DocumentHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLUgpDeposeEtLeTerrainNeDeposePas(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $projetUuid = $fixture['projet']->getUuid()->toRfc4122();

            $this->connecter($client, $fixture['focal']->getEmail());
            $client->request('POST', "/api/v1/documents/projets/{$projetUuid}", files: ['fichier' => $this->fichier()]);
            self::assertResponseStatusCodeSame(403, "Une structure bénéficiaire ne verse rien à la bibliothèque de l'UGP.");

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request(
                'POST',
                "/api/v1/documents/projets/{$projetUuid}",
                parameters: ['libelle' => 'Manuel de procédures', 'visibilite' => 'tous'],
                files: ['fichier' => $this->fichier()],
            );
            self::assertResponseStatusCodeSame(201);

            $corps = $this->corps($client);
            self::assertSame('Manuel de procédures', $corps['libelle']);
            self::assertSame('manuel.pdf', $corps['nomFichier'], "L'intitulé n'écrase pas le nom du fichier déposé.");
            self::assertSame('SISEP Coordination', $corps['deposePar']);

            // Téléchargeable par la route commune des pièces jointes.
            $client->request('GET', "/api/v1/pieces-jointes/{$corps['uuid']}/telecharger");
            self::assertResponseIsSuccessful();
        } finally {
            $this->rollback();
        }
    }

    public function testLaVisibiliteDecideDeCeQueChacunVoit(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $composanteUuid = $fixture['composante']->getUuid()->toRfc4122();

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request(
                'POST',
                "/api/v1/documents/composantes/{$composanteUuid}",
                parameters: ['libelle' => 'Note interne', 'visibilite' => 'equipe_phasaoc'],
                files: ['fichier' => $this->fichier()],
            );
            self::assertResponseStatusCodeSame(201);

            $client->request('GET', "/api/v1/documents/composantes/{$composanteUuid}");
            self::assertCount(1, $this->corps($client)['donnees']);

            // Le point focal n'est pas de l'équipe PHASAOC : la liste ne doit
            // pas lui montrer un document que le téléchargement lui refuserait.
            $this->connecter($client, $fixture['focal']->getEmail());
            $client->request('GET', "/api/v1/documents/composantes/{$composanteUuid}");
            self::assertResponseIsSuccessful();
            self::assertCount(0, $this->corps($client)['donnees']);
        } finally {
            $this->rollback();
        }
    }

    public function testLesFiltresDeLaBibliotheque(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $projetUuid = $fixture['projet']->getUuid()->toRfc4122();
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $this->deposer($client, $projetUuid, 'Manuel de procedures', 'manuel.pdf', 'application/pdf');
            $this->deposer($client, $projetUuid, 'Tableau de suivi', 'suivi.csv', 'text/csv');

            $client->request('GET', "/api/v1/documents/projets/{$projetUuid}");
            self::assertCount(2, $this->corps($client)['donnees']);

            // Famille déduite du MIME, jamais stockée.
            $client->request('GET', "/api/v1/documents/projets/{$projetUuid}?famille=tableur");
            $donnees = $this->corps($client)['donnees'];
            self::assertCount(1, $donnees);
            self::assertSame('Tableau de suivi', $donnees[0]['libelle']);
            self::assertSame('tableur', $donnees[0]['famille']);

            // Recherche libre sur l'intitulé comme sur le nom de fichier.
            $client->request('GET', "/api/v1/documents/projets/{$projetUuid}?q=manuel");
            self::assertCount(1, $this->corps($client)['donnees']);
            $client->request('GET', "/api/v1/documents/projets/{$projetUuid}?q=suivi.csv");
            self::assertCount(1, $this->corps($client)['donnees']);

            // Fenêtre de dépôt : la borne haute inclut le jour entier.
            $aujourdhui = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
            $client->request('GET', "/api/v1/documents/projets/{$projetUuid}?du={$aujourdhui}&au={$aujourdhui}");
            self::assertCount(2, $this->corps($client)['donnees'], "Un document déposé aujourd'hui entre dans la fenêtre du jour.");

            $veille = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->format('Y-m-d');
            $client->request('GET', "/api/v1/documents/projets/{$projetUuid}?au={$veille}");
            self::assertCount(0, $this->corps($client)['donnees']);
        } finally {
            $this->rollback();
        }
    }

    private function deposer(KernelBrowser $client, string $projetUuid, string $libelle, string $nom, string $mime): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'sisep_doc_');
        self::assertNotFalse($chemin);
        file_put_contents($chemin, 'contenu de test');

        $client->request(
            'POST',
            "/api/v1/documents/projets/{$projetUuid}",
            parameters: ['libelle' => $libelle],
            files: ['fichier' => new UploadedFile($chemin, $nom, $mime, null, true)],
        );
        self::assertResponseStatusCodeSame(201);
    }

    public function testUnJustificatifMetierNEstPasRetirableParLaBibliotheque(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            // Pièce jointe d'une autre nature : elle atteste d'une saisie, la
            // retirer viderait cette saisie de sa preuve.
            $justificatif = new PieceJointe(
                'etape_activite',
                (int) $fixture['activite']->getId(),
                'pv.pdf',
                'var/uploads/etapes/pv.pdf',
                'application/pdf',
                12,
                $fixture['coordonnateur'],
            );
            $this->em->persist($justificatif);
            $this->em->flush();

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('DELETE', \sprintf('/api/v1/documents/%s', $justificatif->getUuid()->toRfc4122()));
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    private function fichier(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'sisep_doc_');
        self::assertNotFalse($chemin);
        file_put_contents($chemin, 'manuel de procédures');

        return new UploadedFile($chemin, 'manuel.pdf', 'application/pdf', null, true);
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
        $permDocuments = new Permission('documents.gerer', 'documents', 'Déposer et retirer des documents');
        foreach ([$permConsulter, $permDocuments] as $p) {
            $this->em->persist($p);
        }

        // Le code du rôle compte : PieceJointeAccesResolver reconnaît
        // l'équipe PHASAOC et les sectoriels par leur code de rôle, pas par
        // leurs permissions.
        $roleCoordonnateur = new Role('coordonnateur', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permConsulter);
        $roleCoordonnateur->accorder($permDocuments);
        $this->em->persist($roleCoordonnateur);

        $rolePointFocal = new Role('point_focal_structure', 'Point focal de structure', true);
        $rolePointFocal->accorder($permConsulter);
        $this->em->persist($rolePointFocal);

        $structure = new Structure('DOC-TST', 'Direction de test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        $coordonnateur = new Utilisateur('coordination.doc@example.test', 'temp', 'Coordination', 'SISEP');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $focal = new Utilisateur('focal.doc@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focal->setMotDePasseHash($hasher->hashPassword($focal, self::MOT_DE_PASSE));
        $this->em->persist($focal);
        $this->em->persist(new UtilisateurRole($focal, $rolePointFocal, $structure));

        $projet = new Projet('TESTDOC', 'Projet documents', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, 'CDO', 'Composante documents');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CDO.1', 'Sous-composante documents');
        $this->em->persist($sousComposante);
        $activite = new Activite($sousComposante, 'CDO.1.1', 'Activité documents', $structure);
        $this->em->persist($activite);

        $this->em->flush();

        return [$client, [
            'coordonnateur' => $coordonnateur,
            'focal' => $focal,
            'projet' => $projet,
            'composante' => $composante,
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
