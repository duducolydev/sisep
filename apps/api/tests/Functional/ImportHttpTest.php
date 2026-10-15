<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Permission;
use App\Entity\Personnel;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\Sexe;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Import par fichier Excel : modèle, aperçu sans écriture, confirmation,
 * doublons (dans le fichier et en base), erreurs ligne à ligne, encodage du
 * CSV, contenu qui ne correspond pas à l'extension, droits et portée.
 */
final class ImportHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';
    private const ENTETES_PERSONNEL = ['Prénom *', 'Nom *', 'Sexe (F/M) *', 'Fonction *', 'Structure (sigle)', 'Opération (code PTBA)', 'E-mail', 'Début du contrat', 'Fin du contrat'];

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $temporaires = [];

    public function testLeModeleEstUnClasseurAvecSesListes(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, $f['ugp']);
            $client->request('GET', '/api/v1/imports/personnel/modele.xlsx');
            self::assertResponseIsSuccessful();
            $chemin = $this->temporaire((string) $client->getInternalResponse()->getContent());

            $classeur = IOFactory::load($chemin);
            self::assertSame(['Données', 'Instructions', 'Listes'], $classeur->getSheetNames());
            self::assertSame('Prénom *', $classeur->getSheetByName('Données')?->getCell('A1')->getValue());
            $sigles = array_column($classeur->getSheetByName('Listes')?->rangeToArray('B2:B10', null, false, false) ?? [], 0);
            self::assertContains('DSA', $sigles, 'Les sigles des structures alimentent la liste déroulante.');
        } finally {
            $this->rollback();
        }
    }

    public function testApercuPuisConfirmationAvecDoublonsEtErreurs(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, $f['ugp']);
            $fichier = $this->classeur([
                self::ENTETES_PERSONNEL,
                ['Awa', 'Ndiaye', 'F', 'Enquêtrice superviseure', 'DSA', '9.1.1', 'awa.ndiaye@ansd.sn', '01/01/2026', '31/12/2026'],
                ['AWA', 'ndiaye', 'f', 'Doublon de la ligne 2', '', '', '', '', ''],
                ['Fatou', 'Sarr', 'F', 'Agent existant', '', '', '', '', ''],
                ['Ibou', 'Fall', 'X', '', 'INCONNUE', '7.7.7', 'pas-un-mail', '31/12/2026', '01/01/2026'],
                ['', '', '', '', '', '', '', '', ''],
            ]);

            $client->request('POST', '/api/v1/imports/personnel', files: ['fichier' => $fichier]);
            self::assertResponseIsSuccessful();
            $apercu = $this->corps($client);
            self::assertFalse($apercu['applique']);
            self::assertSame(['lignes' => 4, 'creations' => 1, 'misesAJour' => 0, 'existantes' => 1, 'doublons' => 1, 'erreurs' => 1], $apercu['resume'], 'La ligne vide est sautée.');
            self::assertSame([2, 3, 4, 5], array_column($apercu['lignes'], 'ligne'), 'Numéros de ligne tels qu\'Excel les affiche.');
            $erreur = $apercu['lignes'][3];
            self::assertSame('erreur', $erreur['statut']);
            self::assertCount(6, $erreur['messages'], 'Fonction, sexe, structure, opération, e-mail et dates : chaque problème est signalé.');
            self::assertNull($this->personnel('Awa', 'Ndiaye'), "L'aperçu n'écrit rien.");

            $client->request('POST', '/api/v1/imports/personnel', parameters: ['confirmer' => '1'], files: ['fichier' => $this->copie($fichier)]);
            self::assertResponseStatusCodeSame(201);
            $awa = $this->personnel('Awa', 'Ndiaye');
            self::assertNotNull($awa);
            self::assertSame(Sexe::FEMININ, $awa->getSexe());
            self::assertSame('DSA', $awa->getStructure()?->getSigle());
            self::assertSame('2026-12-31', $awa->getContratCourant()?->getDateFin()?->format('Y-m-d'));

            // Mise à jour demandée : l'agent existant change de fonction.
            $client->request('POST', '/api/v1/imports/personnel', parameters: ['confirmer' => '1', 'miseAJour' => '1'], files: ['fichier' => $this->copie($fichier)]);
            self::assertSame(2, $this->corps($client)['resume']['misesAJour']);
            $this->em->clear();
            self::assertSame('Agent existant', $this->personnel('Fatou', 'Sarr')?->getFonction());
        } finally {
            $this->rollback();
        }
    }

    public function testUnCsvEnWindows1252EstLuCorrectement(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, $f['ugp']);
            $csv = "Prénom;Nom;Sexe;Fonction\nAïssatou;Diène;F;Chargée d'études\n";
            $chemin = $this->temporaire((string) mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));

            $client->request('POST', '/api/v1/imports/personnel', parameters: ['confirmer' => '1'], files: [
                'fichier' => new UploadedFile($chemin, 'personnel.csv', 'text/csv', null, true),
            ]);
            self::assertResponseStatusCodeSame(201);
            self::assertSame('Windows-1252', $this->corps($client)['fichier']['encodage']);
            self::assertNotNull($this->personnel('Aïssatou', 'Diène'), 'Les accents survivent à la conversion.');
        } finally {
            $this->rollback();
        }
    }

    public function testUnFichierDontLeContenuNeCorrespondPasEstRefuse(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, $f['ugp']);
            $chemin = $this->temporaire("%PDF-1.4\n% faux classeur\n");
            $client->request('POST', '/api/v1/imports/personnel', files: [
                'fichier' => new UploadedFile($chemin, 'personnel.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('ne correspond pas', $this->corps($client)['detail']);

            $client->request('POST', '/api/v1/imports/personnel', files: [
                'fichier' => new UploadedFile($this->temporaire('x'), 'personnel.exe', 'application/octet-stream', null, true),
            ]);
            self::assertResponseStatusCodeSame(422);

            // Colonnes obligatoires absentes : refus explicite.
            $client->request('POST', '/api/v1/imports/personnel', files: ['fichier' => $this->classeur([['Nom', 'Prénom'], ['Diop', 'Awa']])]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Sexe', $this->corps($client)['detail']);
        } finally {
            $this->rollback();
        }
    }

    public function testDroitsEtPorteeDesImports(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            // Le point focal ne touche pas aux données RH.
            $this->connecter($client, $f['focal']);
            $client->request('GET', '/api/v1/imports/personnel/modele.xlsx');
            self::assertResponseStatusCodeSame(403);

            // Il importe les points focaux de sa structure, pas des autres.
            $client->request('POST', '/api/v1/imports/points-focaux', parameters: ['confirmer' => '1'], files: ['fichier' => $this->classeur([
                ['Structure (sigle)', 'Prénom et nom', 'Fonction', 'E-mail'],
                ['DSA', 'Mariama Ba', 'Cheffe de division', 'mariama.ba@ansd.sn'],
                ['DSB', 'Ousmane Kane', 'Chef de bureau', ''],
            ])]);
            self::assertResponseStatusCodeSame(201);
            $rapport = $this->corps($client);
            self::assertSame(1, $rapport['resume']['creations']);
            self::assertSame('erreur', $rapport['lignes'][1]['statut']);
            self::assertStringContainsString('hors de votre périmètre', $rapport['lignes'][1]['messages'][0]);
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

        $rhGerer = new Permission('rh.gerer', 'rh', 'Gérer le personnel');
        $pfGerer = new Permission('points_focaux.gerer', 'points_focaux', 'Gérer les points focaux');
        $this->em->persist($rhGerer);
        $this->em->persist($pfGerer);
        // L'UGP consulte aussi les activités : la correspondance « code PTBA
        // → opération » passe par le filtre de portée, comme à la saisie.
        $consulter = new Permission('activites.consulter', 'activites', 'Consulter les activités');
        $this->em->persist($consulter);
        $roleUgp = new Role('ugp_import_test', 'UGP', true);
        $roleUgp->accorder($consulter);
        $roleUgp->accorder($rhGerer);
        $roleUgp->accorder($pfGerer);
        $this->em->persist($roleUgp);
        $roleFocal = new Role('focal_import_test', 'Point focal', true);
        $roleFocal->accorder($pfGerer);
        $this->em->persist($roleFocal);

        $dsa = new Structure('DSA', 'Direction de test A', TypeStructure::DIRECTION_ANSD);
        $dsb = new Structure('DSB', 'Direction de test B', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($dsa);
        $this->em->persist($dsb);

        $comptes = [];
        foreach (['ugp' => [$roleUgp, null], 'focal' => [$roleFocal, $dsa]] as $nom => [$role, $structure]) {
            $utilisateur = new Utilisateur("{$nom}.import@example.test", 'temp', 'Test', ucfirst($nom));
            $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));
            $utilisateur->marquerMdpChange();
            $this->em->persist($utilisateur);
            $this->em->persist(new UtilisateurRole($utilisateur, $role, $structure));
            $comptes[$nom] = $utilisateur->getEmail();
        }

        $projet = new Projet('TESTIMP', 'Projet import', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, '9', 'Composante import');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, '9.1', 'Sous-composante import');
        $this->em->persist($sousComposante);
        $this->em->persist(new Activite($sousComposante, '9.1.1', 'Opération import', $dsa));

        $this->em->persist(new Personnel('Sarr', 'Fatou', Sexe::FEMININ, 'Assistante'));
        $this->em->flush();

        return [$client, $comptes];
    }

    /** @param list<list<string>> $lignes */
    private function classeur(array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet();
        $classeur->getActiveSheet()->setTitle('Données');
        $classeur->getActiveSheet()->fromArray($lignes, null, 'A1', true);
        $chemin = $this->temporaire('');
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Le fichier envoyé est déplacé ou consommé : un second envoi part d'une copie. */
    private function copie(UploadedFile $fichier): UploadedFile
    {
        return new UploadedFile($this->temporaire((string) file_get_contents($fichier->getPathname())), $fichier->getClientOriginalName(), $fichier->getClientMimeType(), null, true);
    }

    private function temporaire(string $contenu): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'sisep_import_');
        self::assertNotFalse($chemin);
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        return $chemin;
    }

    private function personnel(string $prenom, string $nom): ?Personnel
    {
        return $this->em->getRepository(Personnel::class)->findOneBy(['prenom' => $prenom, 'nom' => $nom, 'supprimeLe' => null]);
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
        foreach ($this->temporaires as $chemin) {
            @unlink($chemin);
        }
        parent::tearDown();
    }
}
