<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Composante;
use App\Entity\Permission;
use App\Entity\PointFocal;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Critère d'acceptation P4 (§ 10 du brief) — vérifié ici bout en bout via
 * l'API plutôt qu'en unitaire pur : définir un indicateur, saisir une
 * valeur avec désagrégations, la valider, et lire le taux d'atteinte réel
 * (non borné) dans la réponse. Couvre aussi la séparation définir/saisir
 * et saisir/valider portée par les permissions (§ 7.2).
 */
final class IndicateurHttpTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testDefinirSaisirEtValiderUnIndicateur(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());

            $client->request('POST', '/api/v1/indicateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'code' => 'IOD-TEST',
                'libelle' => 'Indicateur de test',
                'type' => 'IOD',
                'unite' => '%',
                'sens' => 'croissant',
                'valeurReference' => '0',
                'dateReference' => '2025-01-01',
                'valeurCible' => '100',
                'dateCible' => '2028-12-31',
                'frequence' => 'annuelle',
                'source' => 'Test',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $indicateurUuid = $this->corps($client)['uuid'];
            self::assertNull($this->corps($client)['tauxAtteinte'], 'Sans valeur validée, le taux ne doit pas exister.');

            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'valeur' => '130',
                'desagregations' => [
                    ['dimension' => 'sexe', 'modalite' => 'femmes', 'valeur' => '70'],
                    ['dimension' => 'sexe', 'modalite' => 'hommes', 'valeur' => '60'],
                ],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $corpsValeur = $this->corps($client);
            $valeurUuid = $corpsValeur['uuid'];
            self::assertSame('brouillon', $corpsValeur['statut']);
            self::assertCount(2, $corpsValeur['desagregations']);

            // Retours des RSE : la saisie se voit aussitôt, marquée en
            // attente, sans devenir l'actuel officiel avant validation.
            $client->request('GET', "/api/v1/indicateurs/{$indicateurUuid}");
            $ficheAvantValidation = $this->corps($client);
            self::assertNull($ficheAvantValidation['actuel']);
            self::assertNull($ficheAvantValidation['tauxAtteinte']);
            self::assertSame('2026-01-01', $ficheAvantValidation['enAttente']['periode']);
            self::assertEqualsWithDelta(130.0, (float) $ficheAvantValidation['enAttente']['valeur'], 0.0001);
            self::assertEqualsWithDelta(1.3, $ficheAvantValidation['enAttente']['tauxAtteinte'], 0.0001);

            // Le point focal saisie ne détient pas indicateurs.valider : refusé.
            $client->request('POST', "/api/v1/indicateurs/valeurs/{$valeurUuid}/valider");
            self::assertResponseStatusCodeSame(403);

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/indicateurs/valeurs/{$valeurUuid}/valider");
            self::assertResponseIsSuccessful();
            self::assertSame('valide', $this->corps($client)['statut']);

            // 130 % de la cible : le taux réel dépasse 1, il n'est jamais borné côté API (§ 5.2).
            $client->request('GET', "/api/v1/indicateurs/{$indicateurUuid}");
            self::assertResponseIsSuccessful();
            $ficheApresValidation = $this->corps($client);
            self::assertEqualsWithDelta(130.0, (float) $ficheApresValidation['actuel'], 0.0001);
            self::assertEqualsWithDelta(1.3, $ficheApresValidation['tauxAtteinte'], 0.0001);
            self::assertNull($ficheApresValidation['enAttente'], 'Une fois validée, plus rien n\'est en attente.');
        } finally {
            $this->rollback();
        }
    }

    public function testAjustementIncrementalSansSaisiePrealableDemarreDepuisLaValeurReference(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());

            $indicateurUuid = $this->creerIndicateur($client, 'IOD-DELTA-1', valeurReference: '50');

            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'delta' => '12.5',
                'desagregations' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            self::assertSame('62.5000', $this->corps($client)['valeur']);
        } finally {
            $this->rollback();
        }
    }

    public function testResaisieSurUnePeriodeExistanteFaitUnUpsertEtRemetLeStatutEnBrouillon(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $indicateurUuid = $this->creerIndicateur($client, 'IOD-DELTA-2', valeurReference: '0');

            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'valeur' => '40',
                'desagregations' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $valeurUuid = $this->corps($client)['uuid'];

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/indicateurs/valeurs/{$valeurUuid}/valider");
            self::assertResponseIsSuccessful();
            self::assertSame('valide', $this->corps($client)['statut']);

            // Ajustement +5 sur la même période, déjà validée : upsert (200, pas 201/409), remet en brouillon.
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'delta' => '5',
                'desagregations' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(200);
            $corpsApresAjustement = $this->corps($client);
            self::assertSame($valeurUuid, $corpsApresAjustement['uuid'], 'Doit mettre à jour la ligne existante, pas en créer une seconde.');
            self::assertSame('45.0000', $corpsApresAjustement['valeur']);
            self::assertSame('brouillon', $corpsApresAjustement['statut'], 'Une valeur modifiée redevient non-validée (§ séparation saisie/validation).');

            $client->request('GET', "/api/v1/indicateurs/{$indicateurUuid}/valeurs");
            self::assertCount(1, $this->corps($client)['donnees'], 'Une seule ligne pour cette période, pas un doublon.');
        } finally {
            $this->rollback();
        }
    }

    public function testValeurEtDeltaSontMutuellementExclusifs(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $indicateurUuid = $this->creerIndicateur($client, 'IOD-DELTA-3', valeurReference: '0');

            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'desagregations' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'Ni valeur ni delta.');

            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'valeur' => '10',
                'delta' => '5',
                'desagregations' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'Les deux à la fois.');
        } finally {
            $this->rollback();
        }
    }

    public function testInvitationOuvreEtRevoqueLAccesAUnSectorielSurUnSeulIndicateur(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $indicateurCibleUuid = $this->creerIndicateur($client, 'IOD-INV-1', valeurReference: '0');
            $indicateurAutreUuid = $this->creerIndicateur($client, 'IOD-INV-2', valeurReference: '0');

            // Le sectoriel ne détient aucune permission indicateurs.* : tout est refusé au départ.
            $this->connecter($client, $fixture['sectoriel']->getEmail());
            $client->request('GET', '/api/v1/indicateurs');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', "/api/v1/indicateurs/{$indicateurCibleUuid}");
            self::assertResponseStatusCodeSame(403);

            // Le coordonnateur invite le sectoriel sur un seul des deux indicateurs.
            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', "/api/v1/indicateurs/{$indicateurCibleUuid}/invitations", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'utilisateurUuid' => $fixture['sectoriel']->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $invitationUuid = $this->corps($client)['uuid'];

            // L'invité est prévenu par courriel, avec le lien vers la fiche.
            self::assertEmailCount(1);
            $courriel = self::getMailerMessage();
            self::assertNotNull($courriel);
            self::assertEmailAddressContains($courriel, 'To', $fixture['sectoriel']->getEmail());
            self::assertEmailHtmlBodyContains($courriel, 'IOD-INV-1');

            // Invitation en doublon : 409.
            $client->request('POST', "/api/v1/indicateurs/{$indicateurCibleUuid}/invitations", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'utilisateurUuid' => $fixture['sectoriel']->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409);

            $this->connecter($client, $fixture['sectoriel']->getEmail());

            // Accès restreint au seul indicateur invité.
            $client->request('GET', '/api/v1/indicateurs');
            self::assertResponseIsSuccessful();
            $donnees = $this->corps($client)['donnees'];
            self::assertCount(1, $donnees);
            self::assertSame($indicateurCibleUuid, $donnees[0]['uuid']);

            $client->request('GET', "/api/v1/indicateurs/{$indicateurCibleUuid}");
            self::assertResponseIsSuccessful();
            $client->request('GET', "/api/v1/indicateurs/{$indicateurAutreUuid}");
            self::assertResponseStatusCodeSame(403, "L'invitation ne donne accès qu'à l'indicateur ciblé.");

            // La saisie fonctionne aussi sans indicateurs.saisir global.
            $client->request('POST', "/api/v1/indicateurs/{$indicateurCibleUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'valeur' => '10',
                'desagregations' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            // Révocation par le coordonnateur : l'accès disparaît.
            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('DELETE', "/api/v1/indicateurs/invitations/{$invitationUuid}");
            self::assertResponseStatusCodeSame(204);

            // L'octroi ET le retrait d'un droit sont journalisés (CLAUDE.md
            // « toute écriture sensible (budget, droits) est journalisée »),
            // bien que la révocation soit une suppression physique.
            self::assertSame(
                1,
                $this->compterJournal('invitation_indicateur', 'suppression'),
                'La révocation doit laisser une trace au journal.',
            );
            self::assertSame(1, $this->compterJournal('invitation_indicateur', 'creation'));

            $this->connecter($client, $fixture['sectoriel']->getEmail());
            $client->request('GET', "/api/v1/indicateurs/{$indicateurCibleUuid}");
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    public function testPieceJointeSurUneValeurEstTeleversableEtTelechargeable(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $indicateurUuid = $this->creerIndicateur($client, 'IOD-PJ-1', valeurReference: '0');

            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'valeur' => '10',
                'desagregations' => [],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $valeurUuid = $this->corps($client)['uuid'];

            $fichierTmp = tempnam(sys_get_temp_dir(), 'sisep_test_');
            self::assertNotFalse($fichierTmp);
            file_put_contents($fichierTmp, 'justificatif de test');
            $fichier = new UploadedFile($fichierTmp, 'justificatif.pdf', 'application/pdf', null, true);

            $client->request('POST', "/api/v1/indicateurs/valeurs/{$valeurUuid}/pieces-jointes", parameters: ['typeDocument' => 'rapport'], files: ['fichier' => $fichier]);
            self::assertResponseStatusCodeSame(201);
            $corpsValeur = $this->corps($client);
            self::assertCount(1, $corpsValeur['piecesJointes']);
            self::assertSame('justificatif.pdf', $corpsValeur['piecesJointes'][0]['nomFichier']);
            $pieceUuid = $corpsValeur['piecesJointes'][0]['uuid'];

            $client->request('GET', "/api/v1/pieces-jointes/{$pieceUuid}/telecharger");
            self::assertResponseIsSuccessful();
        } finally {
            $this->rollback();
        }
    }

    private function compterJournal(string $entiteType, string $action): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(j) FROM App\Entity\JournalActivite j WHERE j.entiteType = :type AND j.action = :action',
        )->setParameter('type', $entiteType)->setParameter('action', $action)->getSingleScalarResult();
    }

    private function creerIndicateur(KernelBrowser $client, string $code, string $valeurReference): string
    {
        $client->request('POST', '/api/v1/indicateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'code' => $code,
            'libelle' => 'Indicateur de test '.$code,
            'type' => 'IOD',
            'unite' => '%',
            'sens' => 'croissant',
            'valeurReference' => $valeurReference,
            'dateReference' => '2025-01-01',
            'valeurCible' => '100',
            'dateCible' => '2028-12-31',
            'frequence' => 'annuelle',
            'source' => 'Test',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $this->corps($client)['uuid'];
    }

    /**
     * Retours des RSE : les indicateurs transversaux relèvent de l'objectif
     * général, pas d'une composante — le filtre « general » les isole.
     */
    public function testLeFiltreObjectifGeneralIsoleLesIndicateursSansComposante(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $composante = new Composante($fixture['projet'], 'CIG', 'Composante de test');
            $this->em->persist($composante);
            $this->em->flush();

            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $this->creerIndicateur($client, 'OG-1', '0');
            $client->request('POST', '/api/v1/indicateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'code' => 'C1-1',
                'libelle' => 'Indicateur de composante',
                'type' => 'IRI',
                'composante' => $composante->getUuid()->toRfc4122(),
                'unite' => 'Nombre',
                'sens' => 'croissant',
                'valeurReference' => '0',
                'dateReference' => '2025-01-01',
                'valeurCible' => '3',
                'dateCible' => '2028-12-31',
                'frequence' => 'annuelle',
                'source' => 'Test',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $client->request('GET', '/api/v1/indicateurs?composante=general');
            self::assertSame(['OG-1'], array_column($this->corps($client)['donnees'], 'code'));

            $client->request('GET', '/api/v1/indicateurs?composante='.$composante->getUuid()->toRfc4122());
            self::assertSame(['C1-1'], array_column($this->corps($client)['donnees'], 'code'));
        } finally {
            $this->rollback();
        }
    }

    /**
     * Un point focal désigné sur la fiche de sa structure (« Lier un
     * compte ») est invitable, même si son compte ne porte pas le rôle
     * point_focal_structure — la liste restait vide dans ce cas.
     */
    public function testUnCompteLieAUnPointFocalEstInvitable(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $structure = new Structure('PF-INV', 'Structure invitation', TypeStructure::DIRECTION_ANSD);
            $this->em->persist($structure);
            $lie = new Utilisateur('lie.p4@example.test', 'temp', 'Gueye', 'Malick');
            $this->em->persist($lie);
            $pointFocal = new PointFocal($structure, 'Malick Gueye', 'Point focal PHASAOC');
            $pointFocal->setUtilisateur($lie);
            $this->em->persist($pointFocal);
            $this->em->flush();

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('GET', '/api/v1/indicateurs/sectoriels-eligibles');
            self::assertResponseIsSuccessful();
            $noms = array_column($this->corps($client)['donnees'], 'nomComplet');
            self::assertContains($fixture['sectoriel']->getNomComplet(), $noms, 'Le porteur du rôle reste éligible.');
            self::assertContains($lie->getNomComplet(), $noms, 'Le compte lié à une fiche de point focal devient éligible.');
            self::assertSame(\count($noms), \count(array_unique($noms)), 'Aucun doublon.');
        } finally {
            $this->rollback();
        }
    }

    public function testUnRoleSansIndicateursDefinirNePeutPasCreer(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            // Le coordonnateur de cette fixture ne détient que indicateurs.valider, pas .definir.
            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('POST', '/api/v1/indicateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'code' => 'IOD-X',
                'libelle' => 'X',
                'type' => 'IOD',
                'unite' => '%',
                'sens' => 'croissant',
                'valeurReference' => '0',
                'dateReference' => '2025-01-01',
                'valeurCible' => '100',
                'dateCible' => '2028-12-31',
                'frequence' => 'annuelle',
                'source' => 'Test',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    /**
     * Régression (06/11/2026) : un RSE qui saisissait « 0,5 » ou un libellé
     * long recevait « Erreur interne » — MySQL refusait l'INSERT (erreurs
     * 1366 et 1406) et l'exception remontait en 500.
     */
    public function testDefinirAccepteLaVirguleDecimaleEtLesSeparateursDeMilliers(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $client->request('POST', '/api/v1/indicateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(
                $this->definition(['valeurReference' => '0,5', 'valeurIntermediaire' => '1 000', 'valeurCible' => "2\u{202F}500,25"]),
                \JSON_THROW_ON_ERROR,
            ));

            self::assertResponseStatusCodeSame(201);
            $corps = $this->corps($client);
            self::assertEqualsWithDelta(0.5, (float) $corps['valeurReference'], 1e-9);
            self::assertEqualsWithDelta(1000.0, (float) $corps['valeurIntermediaire'], 1e-9);
            self::assertEqualsWithDelta(2500.25, (float) $corps['valeurCible'], 1e-9);
        } finally {
            $this->rollback();
        }
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function definitionsInvalides(): iterable
    {
        yield 'unité collée à la cible' => [['valeurCible' => '100 %'], 'La valeur cible doit être un nombre'];
        yield 'référence en texte' => [['valeurReference' => 'ND'], 'La valeur de référence doit être un nombre'];
        yield 'intermédiaire invalide' => [['valeurIntermediaire' => 'trois'], "L'objectif intermédiaire doit être un nombre"];
        yield 'libellé trop long' => [['libelle' => str_repeat('a', 256)], 'Le libellé ne doit pas dépasser 255 caractères.'];
        yield 'source trop longue' => [['source' => str_repeat('a', 161)], 'La source ne doit pas dépasser 160 caractères.'];
        yield 'unité trop longue' => [['unite' => str_repeat('a', 41)], "L'unité ne doit pas dépasser 40 caractères."];
        yield 'précision trop longue' => [['precisionLibelle' => str_repeat('a', 256)], 'La précision ne doit pas dépasser 255 caractères.'];
        yield 'date mal formée' => [['dateCible' => '31/12/2028'], 'La date cible est invalide.'];
        yield 'composante inconnue' => [['composante' => '00000000-0000-0000-0000-000000000000'], 'Composante introuvable'];
    }

    /**
     * @param array<string, string> $surcharge
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('definitionsInvalides')]
    public function testUneDefinitionInvalideEstRefuseeEn422AvecUnMessageLisible(array $surcharge, string $message): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $client->request('POST', '/api/v1/indicateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(
                $this->definition($surcharge),
                \JSON_THROW_ON_ERROR,
            ));

            self::assertResponseStatusCodeSame(422);
            $corps = $this->corps($client);
            self::assertStringContainsString($message, $corps['detail'] ?? $corps['title']);
        } finally {
            $this->rollback();
        }
    }

    public function testSaisirUneValeurAccepteLaVirguleEtRefuseUneUniteCollee(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $indicateurUuid = $this->creerIndicateur($client, 'IOD-VIRG', '0');

            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'delta' => '2,5',
                'desagregations' => [['dimension' => 'Sexe', 'modalite' => 'Femmes', 'valeur' => '1,5']],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            self::assertEqualsWithDelta(2.5, (float) $this->corps($client)['valeur'], 1e-9);

            $client->request('POST', "/api/v1/indicateurs/{$indicateurUuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-02-01',
                'valeur' => '40 %',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('La valeur doit être un nombre', $this->corps($client)['detail']);
        } finally {
            $this->rollback();
        }
    }

    /**
     * Suppression logique : l'indicateur disparaît de la liste et de la
     * fiche, ses valeurs restent en base, le journal en garde la trace et le
     * code peut resservir.
     */
    public function testSupprimerMasqueLIndicateurGardeSesValeursEtLibereLeCode(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $uuid = $this->creerIndicateur($client, 'IOD-SUP', '0');
            $client->request('POST', "/api/v1/indicateurs/{$uuid}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-01-01',
                'valeur' => '10',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $journalAvant = $this->compterJournal('indicateur', 'modification');

            $client->request('DELETE', "/api/v1/indicateurs/{$uuid}");
            self::assertResponseStatusCodeSame(204);

            $client->request('GET', "/api/v1/indicateurs/{$uuid}");
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/api/v1/indicateurs');
            self::assertNotContains('IOD-SUP', array_column($this->corps($client)['donnees'], 'code'));
            $client->request('DELETE', "/api/v1/indicateurs/{$uuid}");
            self::assertResponseStatusCodeSame(404, 'Une seconde suppression ne trouve plus rien.');

            self::assertSame(1, (int) $this->em->createQuery(
                'SELECT COUNT(v) FROM App\Entity\IndicateurValeur v JOIN v.indicateur i WHERE i.uuid = :uuid',
            )->setParameter('uuid', \Symfony\Component\Uid\Uuid::fromString($uuid), 'uuid')->getSingleScalarResult(), 'Les valeurs ne sont jamais supprimées physiquement.');
            self::assertSame($journalAvant + 1, $this->compterJournal('indicateur', 'modification'), 'La suppression est journalisée.');

            $this->creerIndicateur($client, 'IOD-SUP', '0');
        } finally {
            $this->rollback();
        }
    }

    public function testSupprimerExigeIndicateursDefinir(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $uuid = $this->creerIndicateur($client, 'IOD-DROIT', '0');

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('DELETE', "/api/v1/indicateurs/{$uuid}");
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    /** Redéfinition : code, type, sens, référence et fréquence se corrigent, sous contrôle d'unicité du code. */
    public function testRedefinirUnIndicateurEtRefuserUnCodeDejaPris(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['suiviEvaluation']->getEmail());
            $uuid = $this->creerIndicateur($client, 'IOD-A', '0');
            $this->creerIndicateur($client, 'IOD-B', '0');

            $client->request('PATCH', "/api/v1/indicateurs/{$uuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'code' => 'IRI-A',
                'type' => 'IRI',
                'sens' => 'decroissant',
                'valeurReference' => '1,5',
                'dateReference' => '2024-06-30',
                'frequence' => 'trimestrielle',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $corps = $this->corps($client);
            self::assertSame('IRI-A', $corps['code']);
            self::assertSame('IRI', $corps['type']);
            self::assertSame('decroissant', $corps['sens']);
            self::assertEqualsWithDelta(1.5, (float) $corps['valeurReference'], 1e-9);
            self::assertSame('trimestrielle', $corps['frequence']);

            $client->request('PATCH', "/api/v1/indicateurs/{$uuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['code' => 'IOD-B'], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409);

            $client->request('PATCH', "/api/v1/indicateurs/{$uuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['type' => 'XYZ'], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);

            $client->request('POST', '/api/v1/indicateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->definition(['code' => 'IOD-B']), \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409, 'Deux indicateurs actifs ne partagent pas un code.');
        } finally {
            $this->rollback();
        }
    }

    /**
     * @param array<string, string> $surcharge
     *
     * @return array<string, string>
     */
    private function definition(array $surcharge = []): array
    {
        return array_merge([
            'code' => 'IOD-DEF',
            'libelle' => 'Indicateur de test',
            'type' => 'IOD',
            'unite' => '%',
            'sens' => 'croissant',
            'valeurReference' => '0',
            'dateReference' => '2025-01-01',
            'valeurCible' => '100',
            'dateCible' => '2028-12-31',
            'frequence' => 'annuelle',
            'source' => 'Test',
        ], $surcharge);
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

        $permConsulter = new Permission('indicateurs.consulter', 'indicateurs', 'Consulter le catalogue des indicateurs de performance');
        $permDefinir = new Permission('indicateurs.definir', 'indicateurs', 'Définir un indicateur');
        $permSaisir = new Permission('indicateurs.saisir', 'indicateurs', "Saisir une valeur d'indicateur");
        $permValider = new Permission('indicateurs.valider', 'indicateurs', "Valider une valeur d'indicateur");
        $permInviter = new Permission('indicateurs.inviter', 'indicateurs', 'Inviter un sectoriel à saisir un indicateur');
        foreach ([$permConsulter, $permDefinir, $permSaisir, $permValider, $permInviter] as $p) {
            $this->em->persist($p);
        }

        $roleSuiviEvaluation = new Role('responsable_suivi_evaluation', 'Responsable suivi-évaluation', true);
        $roleSuiviEvaluation->accorder($permConsulter);
        $roleSuiviEvaluation->accorder($permDefinir);
        $roleSuiviEvaluation->accorder($permSaisir);
        $roleSuiviEvaluation->accorder($permInviter);
        $this->em->persist($roleSuiviEvaluation);

        $roleCoordonnateur = new Role('coordonnateur', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permConsulter);
        $roleCoordonnateur->accorder($permValider);
        $roleCoordonnateur->accorder($permInviter);
        $this->em->persist($roleCoordonnateur);

        $roleSectoriel = new Role('point_focal_structure', 'Point focal structure', true);
        $this->em->persist($roleSectoriel);

        $suiviEvaluation = new Utilisateur('suivi.p4@example.test', 'temp', 'Ndiaye', 'Moussa');
        $suiviEvaluation->setMotDePasseHash($hasher->hashPassword($suiviEvaluation, self::MOT_DE_PASSE));
        $suiviEvaluation->marquerMdpChange();
        $this->em->persist($suiviEvaluation);
        $this->em->persist(new UtilisateurRole($suiviEvaluation, $roleSuiviEvaluation, null));

        $coordonnateur = new Utilisateur('coordonnateur.p4@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $coordonnateur->marquerMdpChange();
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        // Sans aucune permission indicateurs.* : sert à vérifier que l'invitation, seule, ouvre l'accès.
        $sectoriel = new Utilisateur('sectoriel.p4@example.test', 'temp', 'Ba', 'Fatou');
        $sectoriel->setMotDePasseHash($hasher->hashPassword($sectoriel, self::MOT_DE_PASSE));
        $sectoriel->marquerMdpChange();
        $this->em->persist($sectoriel);
        $this->em->persist(new UtilisateurRole($sectoriel, $roleSectoriel, null));

        $projet = new Projet('TESTP4', 'Projet test P4', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);

        $this->em->flush();

        return [$client, [
            'suiviEvaluation' => $suiviEvaluation,
            'coordonnateur' => $coordonnateur,
            'sectoriel' => $sectoriel,
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

    protected function tearDown(): void
    {
        $this->rollback();
        parent::tearDown();
    }
}
