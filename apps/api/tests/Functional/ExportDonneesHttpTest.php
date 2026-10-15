<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Exercice;
use App\Entity\Indicateur;
use App\Entity\Permission;
use App\Entity\Personnel;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\FrequenceIndicateur;
use App\Enum\SensIndicateur;
use App\Enum\Sexe;
use App\Enum\TypeIndicateur;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Extractions Excel des écrans : chaque jeu rend un vrai classeur, les
 * données sensibles exigent leur permission d'écran, et la portée par
 * structure vaut pour l'extraction comme pour la consultation.
 */
final class ExportDonneesHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private EntityManagerInterface $em;

    public function testChaqueJeuDeDonneesRendUnClasseur(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'ugp.export@example.test');
            $urls = [
                '/api/v1/exports/donnees/tableau-de-bord.xlsx?exercice=2026',
                '/api/v1/exports/donnees/composantes.xlsx',
                '/api/v1/exports/donnees/activites/'.$f['activiteA'].'.xlsx',
                '/api/v1/exports/donnees/execution.xlsx?trimestre=2',
                '/api/v1/exports/donnees/indicateurs/'.$f['indicateur'].'.xlsx',
                '/api/v1/exports/donnees/documents.xlsx?type=rapport',
                '/api/v1/exports/donnees/personnel.xlsx',
                '/api/v1/exports/donnees/effectifs-enqueteurs.xlsx',
                '/api/v1/exports/donnees/parties-prenantes.xlsx',
                '/api/v1/exports/donnees/comptes.xlsx',
                '/api/v1/exports/donnees/roles.xlsx',
                '/api/v1/exports/donnees/journal.xlsx',
            ];
            foreach ($urls as $url) {
                $client->request('GET', $url);
                self::assertResponseIsSuccessful($url);
                self::assertSame(self::XLSX, $client->getResponse()->headers->get('Content-Type'), $url);
            }

            // Le personnel exporté est lisible : en-tête puis l'agent.
            $client->request('GET', '/api/v1/exports/donnees/personnel.xlsx');
            $chemin = tempnam(sys_get_temp_dir(), 'sisep_export_');
            self::assertNotFalse($chemin);
            file_put_contents($chemin, (string) $client->getInternalResponse()->getContent());
            $feuille = IOFactory::load($chemin)->getSheetByName('Personnel');
            @unlink($chemin);
            self::assertSame('Prénom', $feuille?->getCell('A4')->getValue());
            self::assertSame('Mariama', $feuille->getCell('A5')->getValue());
            self::assertSame('Féminin', $feuille->getCell('C5')->getValue());
        } finally {
            $this->rollback();
        }
    }

    public function testLesExtractionsSensiblesExigentLeurPermission(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            // Le lecteur extrait les données du projet, pas le personnel, les comptes ni le journal.
            $this->connecter($client, 'lecteur.export@example.test');
            $client->request('GET', '/api/v1/exports/donnees/composantes.xlsx');
            self::assertResponseIsSuccessful();
            foreach (['personnel', 'effectifs-enqueteurs', 'comptes', 'roles', 'journal'] as $jeu) {
                $client->request('GET', "/api/v1/exports/donnees/{$jeu}.xlsx");
                self::assertResponseStatusCodeSame(403, $jeu);
            }

            // Portée : une activité d'une autre structure n'est pas extractible.
            $this->connecter($client, 'focal.export@example.test');
            $client->request('GET', '/api/v1/exports/donnees/activites/'.$f['activiteB'].'.xlsx');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/api/v1/exports/donnees/activites/'.$f['activiteA'].'.xlsx');
            self::assertResponseIsSuccessful();
        } finally {
            $this->rollback();
        }
    }

    /** @return array{0: KernelBrowser, 1: array<string, string>} */
    private function demarrer(): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $permissions = [];
        foreach (['activites.consulter', 'exports.generer', 'indicateurs.consulter', 'rh.consulter', 'admin.utilisateurs', 'admin.roles', 'journal.consulter'] as $code) {
            $permissions[$code] = new Permission($code, explode('.', $code)[0], $code);
            $this->em->persist($permissions[$code]);
        }
        $roles = [];
        foreach ([
            'ugp' => array_keys($permissions),
            'lecteur' => ['activites.consulter', 'exports.generer'],
            'focal' => ['activites.consulter', 'exports.generer'],
        ] as $nom => $codes) {
            $roles[$nom] = new Role("{$nom}_export_test", ucfirst($nom), true);
            foreach ($codes as $code) {
                $roles[$nom]->accorder($permissions[$code]);
            }
            $this->em->persist($roles[$nom]);
        }

        $structureA = new Structure('EXA', 'Structure A', TypeStructure::DIRECTION_ANSD);
        $structureB = new Structure('EXB', 'Structure B', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structureA);
        $this->em->persist($structureB);

        foreach (['ugp' => null, 'lecteur' => null, 'focal' => $structureA] as $nom => $structure) {
            $u = new Utilisateur("{$nom}.export@example.test", 'temp', 'Test', ucfirst($nom));
            $u->setMotDePasseHash($hasher->hashPassword($u, self::MOT_DE_PASSE));
            $u->marquerMdpChange();
            $this->em->persist($u);
            $this->em->persist(new UtilisateurRole($u, $roles[$nom], $structure));
        }

        $projet = new Projet('TESTEXP', 'Projet export', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $this->em->persist(new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        $composante = new Composante($projet, 'CX', 'Composante export');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CX.1', 'Sous-composante export');
        $this->em->persist($sousComposante);
        $activiteA = new Activite($sousComposante, 'CX.1.1', 'Activité A', $structureA);
        $activiteB = new Activite($sousComposante, 'CX.1.2', 'Activité B', $structureB);
        $this->em->persist($activiteA);
        $this->em->persist($activiteB);

        $indicateur = new Indicateur($projet, 'IX-1', 'Indicateur export', TypeIndicateur::IRI, 'Nombre', SensIndicateur::CROISSANT, '0', new \DateTimeImmutable('2025-01-01'), '10', new \DateTimeImmutable('2028-12-31'), FrequenceIndicateur::ANNUELLE, 'Test');
        $this->em->persist($indicateur);

        $this->em->persist(new Personnel('Ba', 'Mariama', Sexe::FEMININ, 'Chargée de suivi'));
        $this->em->flush();

        return [$client, [
            'activiteA' => $activiteA->getUuid()->toRfc4122(),
            'activiteB' => $activiteB->getUuid()->toRfc4122(),
            'indicateur' => $indicateur->getUuid()->toRfc4122(),
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

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }
}
