<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Amorcage\ReferentielApplicatif;
use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\Exercice;
use App\Entity\Indicateur;
use App\Entity\IndicateurValeur;
use App\Entity\Projet;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\FrequenceIndicateur;
use App\Enum\SensIndicateur;
use App\Enum\StatutEcriture;
use App\Enum\TypeIndicateur;
use App\Enum\TypeStructure;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Import des engagements et des dépenses depuis Excel, validation en lot et
 * classeur de la revue à mi-parcours (demande de l'UGP, octobre 2026), avec
 * le catalogue de rôles réel.
 *
 * Ce que les tests verrouillent : l'import ne contourne aucun contrôle
 * budgétaire. Les écritures arrivent en brouillon, le plafond est vérifié à
 * la validation, et personne ne valide ce qu'il a importé.
 */
final class ImportBudgetHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';
    private const ENTETES_ENGAGEMENTS = ['Ligne budgétaire', 'Référence', 'Nature', 'Montant (FCFA)', 'Date d\'engagement', 'Exercice', 'Observations'];
    private const ENTETES_DEPENSES = ['Référence de l\'engagement', 'Montant (FCFA)', 'Date de paiement', 'Référence du paiement', 'Exercice de l\'engagement'];

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $temporaires = [];

    public function testImportDesEngagementsPuisValidationEnLot(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $annee = (string) $f['annee'];
            $date = "15/03/{$annee}";
            $fichier = $this->classeur([
                self::ENTETES_ENGAGEMENTS,
                ['9.1.1', 'BC-1', 'Fournitures', '4000000', $date, '', 'Tablettes'],
                ['9.1.2 — Ligne dotée', 'BC-2', 'Travaux (construction, réaménagement…)', '6000000', $date, '', ''],
                ['9.1.2 — Ligne sans dotation', 'BC-3', 'Fournitures', '100000', $date, '', ''],
                ['9.1.2', 'BC-5', 'Fournitures', '100000', $date, '', ''],
                ['9.1.1 — Activité sans ligne', 'bc-1', 'Fournitures', '1000', $date, '', ''],
                ['9.1.1', 'BC-4', 'service_consultation', '1 250 000', $date, '', ''],
                ['9.1.1', 'BC-6', 'Cadeaux', '10,5', 'hier', '', ''],
            ]);

            // Le coordonnateur valide mais ne saisit pas : l'import lui est fermé.
            $this->connecter($client, 'coord@example.test');
            $client->request('POST', '/api/v1/imports/engagements', files: ['fichier' => $fichier]);
            self::assertResponseStatusCodeSame(403);

            $this->connecter($client, 'gestion@example.test');
            $client->request('GET', '/api/v1/imports/engagements/modele.xlsx');
            self::assertResponseIsSuccessful();

            $client->request('POST', '/api/v1/imports/engagements', files: ['fichier' => $this->copie($fichier)]);
            self::assertResponseIsSuccessful();
            $apercu = $this->corps($client);
            self::assertSame(['lignes' => 7, 'creations' => 3, 'misesAJour' => 0, 'existantes' => 0, 'doublons' => 1, 'erreurs' => 3], $apercu['resume']);
            $statuts = array_column($apercu['lignes'], 'statut', 'ligne');
            self::assertSame(['creation', 'creation', 'erreur', 'erreur', 'doublon', 'creation', 'erreur'], array_values($statuts));
            // Montant::formater() sépare les milliers par une espace insécable.
            $messages = (string) preg_replace('/\s/u', ' ', implode(' ', $apercu['lignes'][1]['messages']));
            self::assertStringContainsString('atteindrait 6 000 000 FCFA pour une dotation de 5 000 000 FCFA', $messages, 'Le plafond est annoncé dès l\'aperçu.');
            self::assertStringContainsString('Aucune dotation', $apercu['lignes'][2]['messages'][0]);
            self::assertStringContainsString('précisez laquelle', $apercu['lignes'][3]['messages'][0]);
            self::assertCount(3, $apercu['lignes'][6]['messages'], 'Nature, montant et date : chaque problème est signalé.');

            $client->request('POST', '/api/v1/imports/engagements', parameters: ['confirmer' => '1'], files: ['fichier' => $this->copie($fichier)]);
            self::assertResponseStatusCodeSame(201);
            $importes = $this->em->getRepository(Engagement::class)->findBy(['exercice' => $f['exercice']], ['referenceMarche' => 'ASC']);
            self::assertSame(['BC-1', 'BC-2', 'BC-4'], array_map(static fn (Engagement $e) => $e->getReferenceMarche(), $importes));
            foreach ($importes as $e) {
                self::assertSame(StatutEcriture::BROUILLON, $e->getStatut(), 'Un import ne valide jamais.');
                self::assertSame('gestion@example.test', $e->getCreePar()->getEmail());
            }
            self::assertSame(1_250_000, $importes[2]->getMontant()->versFcfa(), '« 1 250 000 » est lu comme un montant.');

            // Réimporter le même fichier n'ajoute rien.
            $client->request('POST', '/api/v1/imports/engagements', parameters: ['confirmer' => '1'], files: ['fichier' => $this->copie($fichier)]);
            self::assertSame(3, $this->corps($client)['resume']['existantes']);

            // Validation en lot : le gestionnaire n'a pas le droit de valider…
            $uuids = array_map(static fn (Engagement $e) => $e->getUuid()->toRfc4122(), $importes);
            $client->request('POST', '/api/v1/engagements/valider-lot', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['engagements' => $uuids], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);

            // … le coordonnateur, si : BC-2 dépasse la dotation de sa ligne, il est écarté.
            $this->connecter($client, 'coord@example.test');
            $client->request('GET', '/api/v1/budget/a-valider');
            self::assertResponseIsSuccessful();
            self::assertCount(3, $this->corps($client)['engagements']);
            $client->request('POST', '/api/v1/engagements/valider-lot', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['engagements' => $uuids], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $lot = $this->corps($client);
            self::assertSame(2, $lot['validees']);
            self::assertCount(1, $lot['refus']);
            self::assertSame('BC-2', $lot['refus'][0]['reference']);
            self::assertStringContainsString('au-delà de la dotation', $lot['refus'][0]['motif']);
        } finally {
            $this->rollback();
        }
    }

    public function testImportDesDepensesEtSeparationSaisieValidation(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $annee = (string) $f['annee'];
            // Deux engagements de départ : BC-1 validé (4 M), BC-2 en brouillon.
            $engagements = $this->engagements($f);

            $fichier = $this->classeur([
                self::ENTETES_DEPENSES,
                ['BC-1', '3000000', "30/04/{$annee}", 'OP-1', ''],
                ['BC-1', '2000000', "30/05/{$annee}", 'OP-2', ''],
                ['BC-9', '1000', "30/05/{$annee}", 'OP-3', ''],
                ['bc-1', '3000000', "30/04/{$annee}", 'op-1', ''],
                ['BC-2', '100000', "30/05/{$annee}", 'OP-4', ''],
            ]);

            $this->connecter($client, 'gestion@example.test');
            $client->request('POST', '/api/v1/imports/depenses', files: ['fichier' => $fichier]);
            self::assertResponseIsSuccessful();
            $apercu = $this->corps($client);
            self::assertSame(['lignes' => 5, 'creations' => 3, 'misesAJour' => 0, 'existantes' => 0, 'doublons' => 1, 'erreurs' => 1], $apercu['resume']);
            self::assertStringContainsString('atteindrait', implode(' ', $apercu['lignes'][1]['messages']), 'Le décaissé au-delà de l\'engagé est annoncé.');
            self::assertStringContainsString('Aucun engagement', $apercu['lignes'][2]['messages'][0]);
            self::assertStringContainsString('pas encore validé', implode(' ', $apercu['lignes'][4]['messages']));

            $client->request('POST', '/api/v1/imports/depenses', parameters: ['confirmer' => '1'], files: ['fichier' => $this->copie($fichier)]);
            self::assertResponseStatusCodeSame(201);

            // Le gestionnaire saisit sans valider : la validation en lot lui est fermée.
            $this->connecter($client, 'coord@example.test');
            $client->request('GET', '/api/v1/budget/a-valider');
            $depenses = $this->corps($client)['depenses'];
            self::assertCount(3, $depenses);
            $uuids = array_column($depenses, 'uuid');
            $this->connecter($client, 'gestion@example.test');
            $client->request('POST', '/api/v1/depenses/valider-lot', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['depenses' => $uuids], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);

            // Le coordonnateur valide OP-1 ; OP-2 dépasserait l'engagé, OP-4 attend son engagement.
            $this->connecter($client, 'coord@example.test');
            $client->request('POST', '/api/v1/depenses/valider-lot', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['depenses' => $uuids], \JSON_THROW_ON_ERROR));
            $lot = $this->corps($client);
            self::assertSame(1, $lot['validees']);
            $motifs = array_column($lot['refus'], 'motif', 'reference');
            self::assertStringContainsString('au-delà du montant engagé', $motifs['OP-2']);
            self::assertStringContainsString('doit être validé', $motifs['OP-4']);
            $op1 = $this->em->getRepository(Depense::class)->findOneBy(['referencePaiement' => 'OP-1']);
            self::assertNotNull($op1);
            self::assertSame(StatutEcriture::VALIDE, $op1->getStatut());
            self::assertSame($engagements['BC-1']->getId(), $op1->getEngagement()->getId());
        } finally {
            $this->rollback();
        }
    }

    /**
     * Décision du 28/10/2026 : le RSE valide, mais n'a pas besoin d'être
     * validé. Ses engagements et dépenses sont validés dès la saisie, plafond
     * compris ; ce qu'il importe, il le valide lui-même. Les autres profils
     * ne changent pas : le gestionnaire saisit en brouillon, l'administrateur
     * ne valide toujours pas ce qu'il a saisi.
     */
    public function testLeRseNAPasBesoinDEtreValide(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $activite = $f['activite']->getUuid()->toRfc4122();
            $annee = $f['annee'];
            $engagement = fn (int $montant, string $reference): string => json_encode([
                'activite' => $activite, 'exercice' => $annee, 'nature' => 'fournitures', 'montant' => $montant,
                'dateEngagement' => "{$annee}-03-15", 'referenceMarche' => $reference,
            ], \JSON_THROW_ON_ERROR);
            $json = ['CONTENT_TYPE' => 'application/json'];

            $this->connecter($client, 'rse@example.test');
            $client->request('POST', '/api/v1/engagements', server: $json, content: $engagement(4_000_000, 'RSE-1'));
            self::assertResponseStatusCodeSame(201);
            $saisi = $this->corps($client);
            self::assertSame('valide', $saisi['statut'], 'Validé dès la saisie.');

            // Au-delà de la dotation (10 M, dont 4 M déjà engagés), rien n'est créé.
            $client->request('POST', '/api/v1/engagements', server: $json, content: $engagement(7_000_000, 'RSE-2'));
            self::assertResponseStatusCodeSame(422);
            self::assertNull($this->em->getRepository(Engagement::class)->findOneBy(['referenceMarche' => 'RSE-2']), 'Refusé en entier, pas laissé en brouillon.');

            // Dépense sur son engagement validé : validée elle aussi.
            $client->request('POST', '/api/v1/depenses', server: $json, content: json_encode([
                'engagement' => $saisi['uuid'], 'montant' => 1_000_000, 'dateDepense' => "{$annee}-04-30", 'referencePaiement' => 'OP-RSE',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            self::assertSame('valide', $this->corps($client)['statut']);

            // Ce qu'il importe arrive en brouillon ; il le valide lui-même.
            $client->request('POST', '/api/v1/imports/engagements', parameters: ['confirmer' => '1'], files: ['fichier' => $this->classeur([
                self::ENTETES_ENGAGEMENTS,
                ['9.1.1', 'RSE-3', 'Fournitures', '1000000', "15/03/{$annee}", '', ''],
            ])]);
            self::assertResponseStatusCodeSame(201);
            $importe = $this->em->getRepository(Engagement::class)->findOneBy(['referenceMarche' => 'RSE-3']);
            self::assertNotNull($importe);
            self::assertSame(StatutEcriture::BROUILLON, $importe->getStatut());
            $client->request('POST', '/api/v1/engagements/valider-lot', server: $json, content: json_encode(['engagements' => [$importe->getUuid()->toRfc4122()]], \JSON_THROW_ON_ERROR));
            self::assertSame(1, $this->corps($client)['validees']);

            // Le gestionnaire budgétaire : toujours en brouillon.
            $this->connecter($client, 'gestion@example.test');
            $client->request('POST', '/api/v1/engagements', server: $json, content: $engagement(100_000, 'GEST-1'));
            self::assertResponseStatusCodeSame(201);
            self::assertSame('brouillon', $this->corps($client)['statut']);

            // L'administrateur saisit et valide, mais jamais sa propre saisie.
            $this->connecter($client, 'admin@example.test');
            $client->request('POST', '/api/v1/engagements', server: $json, content: $engagement(100_000, 'ADM-1'));
            self::assertResponseStatusCodeSame(201);
            $admin = $this->corps($client);
            self::assertSame('brouillon', $admin['statut']);
            $client->request('POST', '/api/v1/engagements/'.$admin['uuid'].'/valider');
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    public function testLeClasseurDeLaRevueAMiParcours(): void
    {
        [$client] = $this->demarrer();

        try {
            $this->connecter($client, 'focal@example.test');
            $client->request('GET', '/api/v1/exports/donnees/revue-mi-parcours.xlsx');
            self::assertResponseStatusCodeSame(403, 'Réservé à l\'équipe du projet.');

            $this->connecter($client, 'rse@example.test');
            $client->request('GET', '/api/v1/exports/donnees/revue-mi-parcours.xlsx');
            $contenu = (string) $client->getInternalResponse()->getContent();
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('spreadsheetml', (string) $client->getResponse()->headers->get('Content-Type'));

            $chemin = $this->temporaire($contenu);
            $classeur = IOFactory::load($chemin);
            self::assertSame(
                ['Synthèse', 'Cadre de résultats', 'Exécution composantes', 'Activités', 'Exécution pluriannuelle', 'Engagements par nature', 'Reporting structures', 'Points d\'attention'],
                $classeur->getSheetNames(),
            );

            // IND-A : référence 0, objectif intermédiaire 5, cible 10, valeur validée 6.
            $resultats = $classeur->getSheetByName('Cadre de résultats');
            self::assertNotNull($resultats);
            $lignes = $resultats->toArray(null, true, false);
            $parCode = [];
            foreach (\array_slice($lignes, 4) as $ligne) {
                $parCode[$ligne[0]] = $ligne;
            }
            self::assertEqualsWithDelta(1.2, $parCode['IND-A'][14], 0.0001, 'Progression vers l\'objectif intermédiaire : (6 − 0) / (5 − 0).');
            self::assertSame('Atteint', $parCode['IND-A'][15]);
            self::assertEqualsWithDelta(0.6, $parCode['IND-A'][16], 0.0001, 'Progression vers la cible : (6 − 0) / (10 − 0).');
            self::assertSame('Non renseigné', $parCode['IND-B'][15]);
        } finally {
            $this->rollback();
        }
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, Engagement>
     */
    private function engagements(array $f): array
    {
        $gestion = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'gestion@example.test']);
        $coord = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'coord@example.test']);
        $exercice = $this->em->getRepository(Exercice::class)->find($f['exercice']->getId());
        $activite = $this->em->getRepository(Activite::class)->find($f['activite']->getId());
        self::assertNotNull($gestion);
        self::assertNotNull($coord);
        self::assertNotNull($exercice);
        self::assertNotNull($activite);

        $resultat = [];
        foreach (['BC-1' => [4_000_000, true], 'BC-2' => [500_000, false]] as $reference => [$montant, $valide]) {
            $e = new Engagement($activite, $exercice, \App\Enum\NatureEngagement::FOURNITURES, Montant::fromFcfa($montant), new \DateTimeImmutable(), $gestion);
            $e->setReferenceMarche($reference);
            if ($valide) {
                $e->valider($coord);
            }
            $this->em->persist($e);
            $resultat[$reference] = $e;
        }
        $this->em->flush();

        return $resultat;
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

        $referentiel = $container->get(ReferentielApplicatif::class);
        $roles = $referentiel->semerRoles($referentiel->semerPermissions());

        $structure = new Structure('IMP-T', 'Structure import', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);

        foreach ([
            'gestion' => ['gestionnaire_budgetaire', null],
            'rse' => ['responsable_suivi_evaluation', null],
            'coord' => ['coordonnateur', null],
            'admin' => ['administrateur', null],
            'focal' => ['point_focal_structure', $structure],
        ] as $nom => [$codeRole, $portee]) {
            $u = new Utilisateur($nom.'@example.test', 'temp', 'Test', $nom);
            $u->setMotDePasseHash($hasher->hashPassword($u, self::MOT_DE_PASSE));
            $u->marquerMdpChange();
            $this->em->persist($u);
            $this->em->persist(new UtilisateurRole($u, $roles[$codeRole], $portee));
        }

        $annee = (int) date('Y');
        $projet = new Projet('TESTIMP', 'Projet import', 'Banque mondiale', new \DateTimeImmutable(($annee - 1).'-01-01'), new \DateTimeImmutable(($annee + 2).'-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, $annee, new \DateTimeImmutable("{$annee}-01-01"), new \DateTimeImmutable("{$annee}-12-31"));
        $this->em->persist($exercice);
        $composante = new Composante($projet, '9', 'Composante import');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, '9.1', 'Sous-composante import');
        $this->em->persist($sousComposante);

        // 9.1.1 sans ligne, dotée de 10 M ; 9.1.2 avec une ligne dotée de 5 M et une sans dotation.
        $activite = new Activite($sousComposante, '9.1.1', 'Activité sans ligne', $structure);
        $activiteLignes = new Activite($sousComposante, '9.1.2', 'Activité à lignes', $structure);
        $this->em->persist($activite);
        $this->em->persist($activiteLignes);
        $ligneDotee = new SousActivite($activiteLignes, 'Ligne dotée');
        $ligneSansDotation = new SousActivite($activiteLignes, 'Ligne sans dotation');
        $this->em->persist($ligneDotee);
        $this->em->persist($ligneSansDotation);
        $auteur = new Utilisateur('auteur.dotation@example.test', 'temp', 'Test', 'Auteur');
        $this->em->persist($auteur);
        $this->em->persist(new Dotation($activite, $exercice, Montant::fromFcfa(10_000_000), $auteur));
        $this->em->persist(new Dotation($ligneDotee, $exercice, Montant::fromFcfa(5_000_000), $auteur));

        // Cadre de résultats : IND-A renseigné et validé, IND-B sans valeur.
        foreach (['IND-A', 'IND-B'] as $code) {
            $indicateur = new Indicateur($projet, $code, "Indicateur {$code}", TypeIndicateur::IRI, 'Nombre', SensIndicateur::CROISSANT, '0', new \DateTimeImmutable(($annee - 1).'-01-01'), '10', new \DateTimeImmutable(($annee + 2).'-12-31'), FrequenceIndicateur::ANNUELLE, 'Source');
            $indicateur->setValeurIntermediaire('5');
            $indicateur->setDateIntermediaire(new \DateTimeImmutable("{$annee}-12-31"));
            $this->em->persist($indicateur);
            if ('IND-A' === $code) {
                $valeur = new IndicateurValeur($indicateur, new \DateTimeImmutable("{$annee}-06-30"), '6', $auteur);
                $valeur->valider($auteur);
                $this->em->persist($valeur);
            }
        }

        $this->em->flush();

        return [$client, ['exercice' => $exercice, 'activite' => $activite, 'annee' => $annee]];
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
        $chemin = tempnam(sys_get_temp_dir(), 'sisep_import_budget_');
        self::assertNotFalse($chemin);
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        return $chemin;
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
