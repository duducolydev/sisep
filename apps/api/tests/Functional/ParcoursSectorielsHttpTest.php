<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Amorcage\ReferentielApplicatif;
use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\EtapeActivite;
use App\Entity\Exercice;
use App\Entity\Indicateur;
use App\Entity\PointFocal;
use App\Entity\Projet;
use App\Entity\RapportPeriodique;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\FrequenceIndicateur;
use App\Enum\NatureEngagement;
use App\Enum\PeriodeTrimestre;
use App\Enum\SensIndicateur;
use App\Enum\TypeIndicateur;
use App\Enum\TypeStructure;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Parcours de bout en bout des sectoriels et de l'équipe de suivi, avec le
 * catalogue de rôles réel (ReferentielApplicatif, celui de la production) —
 * pas des rôles fabriqués pour le test : ce sont les droits effectifs de
 * chaque profil qui sont vérifiés (audit du 26/10/2026).
 *
 *  - l'avancement des étapes fait celui des lignes et de l'activité,
 *    identique dans l'arbre et sur la fiche ;
 *  - le point focal d'une ligne confiée à sa direction, dans l'activité
 *    d'une autre, la trouve et la renseigne — elle seule ;
 *  - un sectoriel invité saisit un indicateur ; sa valeur attend la
 *    validation, le responsable suivi-évaluation la voit et la valide, puis
 *    elle est prise en compte ; personne ne valide sa propre saisie.
 */
final class ParcoursSectorielsHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLePointFocalDUneLigneLaTrouveEtLaRenseigneElleSeule(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $activite = $f['activite']->getUuid()->toRfc4122();
            $arbre = '/api/v1/projets/'.$f['projet']->getUuid()->toRfc4122().'/arbre';

            // Point focal CSI : sa direction n'a qu'une ligne, dans une activité de la DPEE.
            $this->connecter($client, 'focal.csi@example.test');
            $client->request('GET', $arbre);
            self::assertContains($activite, $this->activitesDeLArbre($client), "L'activité qui contient sa ligne figure dans son arbre.");
            $client->request('GET', '/api/v1/activites/'.$activite);
            self::assertResponseIsSuccessful('Il ouvre la fiche de cette activité.');
            $client->request('GET', '/api/v1/sous-activites/'.$f['ligneDpee']->getUuid()->toRfc4122().'/etapes');
            self::assertResponseIsSuccessful('Il lit aussi les autres lignes de l\'activité.');

            $this->renseigner($client, $f['etapeCsi1'], 50);
            self::assertResponseIsSuccessful('Il renseigne une étape de sa ligne.');
            $this->renseigner($client, $f['etapeDpee'], 10);
            self::assertResponseStatusCodeSame(403, 'Pas une étape d\'une ligne de la DPEE.');
            $this->renseigner($client, $f['etapePropre'], 10);
            self::assertResponseStatusCodeSame(403, 'Pas une étape propre de l\'activité, qui relève de la DPEE.');

            // Point focal DPEE : l'activité et sa propre ligne, pas la ligne du CSI.
            $this->connecter($client, 'focal.dpee@example.test');
            $this->renseigner($client, $f['etapeDpee'], 100);
            self::assertResponseIsSuccessful();
            $this->renseigner($client, $f['etapePropre'], 0);
            self::assertResponseIsSuccessful();
            $this->renseigner($client, $f['etapeCsi2'], 100);
            self::assertResponseStatusCodeSame(403);

            // Une structure étrangère à l'activité ne la voit pas.
            $this->connecter($client, 'focal.dsid@example.test');
            $client->request('GET', $arbre);
            self::assertNotContains($activite, $this->activitesDeLArbre($client));
            $client->request('GET', '/api/v1/activites/'.$activite);
            self::assertContains($client->getResponse()->getStatusCode(), [403, 404]);

            // Progression : ligne CSI = (50 + 0) / 2 = 25 ; ligne DPEE = 100 ;
            // étapes propres = 0 ; activité = (25 + 100 + 0) / 3 ≈ 41,67.
            $this->connecter($client, 'rse@example.test');
            $client->request('GET', '/api/v1/activites/'.$activite);
            self::assertResponseIsSuccessful();
            $fiche = $this->corps($client);
            self::assertEqualsWithDelta(0.4167, $fiche['progression'], 0.0001);
            $lignes = array_column($fiche['sousActivites'], 'progression', 'libelle');
            self::assertEqualsWithDelta(0.25, $lignes['Ligne CSI'], 0.0001);
            self::assertEqualsWithDelta(1.0, $lignes['Ligne DPEE'], 0.0001);

            $client->request('GET', $arbre);
            $noeud = $this->noeudActivite($client, $activite);
            self::assertSame(42, $noeud['avancement'], "L'arbre applique la même règle, arrondie.");
            self::assertSame([25, 100], array_column($noeud['sousActivites'], 'avancement'));
        } finally {
            $this->rollback();
        }
    }

    public function testUnSectorielInviteSaisitEtSaValeurEstValideePuisPriseEnCompte(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $indicateur = '/api/v1/indicateurs/'.$f['indicateur']->getUuid()->toRfc4122();

            // Sans invitation, le module lui est fermé.
            $this->connecter($client, 'focal.csi@example.test');
            $client->request('GET', '/api/v1/indicateurs');
            self::assertResponseStatusCodeSame(403);

            $this->connecter($client, 'rse@example.test');
            $client->request('POST', $indicateur.'/invitations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'utilisateurUuid' => $f['focalCsi']->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $invitation = $this->corps($client)['uuid'];

            $this->connecter($client, 'focal.csi@example.test');
            $client->request('GET', '/api/v1/auth/moi');
            self::assertTrue($this->corps($client)['aAccesIndicateurs'], 'Le menu Indicateurs lui apparaît.');
            $client->request('GET', '/api/v1/indicateurs');
            self::assertSame(['IND-T'], array_column($this->corps($client)['donnees'], 'code'), 'Il ne voit que l\'indicateur où il est invité.');
            $client->request('GET', $indicateur);
            self::assertTrue($this->corps($client)['peutSaisir']);

            $client->request('POST', $indicateur.'/valeurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-09-30',
                'valeur' => '4',
                'desagregations' => [['dimension' => 'sexe', 'modalite' => 'femmes', 'valeur' => '2']],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            $valeur = $this->corps($client);
            self::assertSame('brouillon', $valeur['statut'], 'Une saisie attend toujours sa validation.');

            // Le responsable suivi-évaluation la voit en attente, et la valide.
            $this->connecter($client, 'rse@example.test');
            $client->request('GET', $indicateur);
            $fiche = $this->corps($client);
            self::assertEqualsWithDelta(4.0, (float) $fiche['enAttente']['valeur'], 0.0001);
            self::assertEqualsWithDelta(0.4, $fiche['enAttente']['tauxAtteinte'], 0.0001, 'Taux provisoire : (4 − 0) / (10 − 0).');
            self::assertNull($fiche['tauxAtteinte'], 'Rien d\'officiel tant que ce n\'est pas validé.');
            $client->request('GET', '/api/v1/indicateurs');
            self::assertNotNull($this->corps($client)['donnees'][0]['enAttente'], 'La liste signale aussi la valeur en attente.');
            self::assertSame('focal.csi Test', $this->valeurDeLaSerie($client, $indicateur, $valeur['uuid'])['saisiPar']['nomComplet']);
            $client->request('POST', '/api/v1/indicateurs/valeurs/'.$valeur['uuid'].'/valider');
            self::assertResponseIsSuccessful('Le RSE valide la saisie du sectoriel (décision du 26/10/2026).');
            $client->request('POST', '/api/v1/indicateurs/valeurs/'.$valeur['uuid'].'/valider');
            self::assertResponseStatusCodeSame(409, 'Une valeur validée ne se revalide pas.');
            $client->request('GET', $indicateur);
            $fiche = $this->corps($client);
            self::assertEqualsWithDelta(4.0, (float) $fiche['actuel'], 0.0001);
            self::assertEqualsWithDelta(0.4, $fiche['tauxAtteinte'], 0.0001);
            self::assertNull($fiche['enAttente']);

            // Correction par le sectoriel : la ventilation est corrigée, pas doublée.
            $this->connecter($client, 'focal.csi@example.test');
            $client->request('POST', $indicateur.'/valeurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-09-30',
                'valeur' => '6',
                'desagregations' => [['dimension' => 'sexe', 'modalite' => 'femmes', 'valeur' => '3']],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $corrigee = $this->corps($client);
            self::assertSame('brouillon', $corrigee['statut'], 'Une valeur corrigée repasse en validation.');
            self::assertCount(1, $corrigee['desagregations']);
            self::assertEqualsWithDelta(3.0, (float) $corrigee['desagregations'][0]['valeur'], 0.0001);

            // Le RSE corrige à son tour le chiffre : il en devient l'auteur, et
            // ne peut plus le valider lui-même — le coordonnateur, si.
            $this->connecter($client, 'rse@example.test');
            $client->request('POST', $indicateur.'/valeurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'periode' => '2026-09-30',
                'valeur' => '5',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $client->request('POST', '/api/v1/indicateurs/valeurs/'.$corrigee['uuid'].'/valider');
            self::assertResponseStatusCodeSame(422, 'Personne ne valide sa propre saisie.');
            $this->connecter($client, 'coord@example.test');
            $client->request('POST', '/api/v1/indicateurs/valeurs/'.$corrigee['uuid'].'/valider');
            self::assertResponseIsSuccessful();
            $client->request('GET', $indicateur);
            self::assertEqualsWithDelta(5.0, (float) $this->corps($client)['actuel'], 0.0001);

            // L'invitation révoquée, l'accès tombe.
            $this->connecter($client, 'rse@example.test');
            $client->request('DELETE', '/api/v1/indicateurs/invitations/'.$invitation);
            self::assertResponseStatusCodeSame(204);
            $this->connecter($client, 'focal.csi@example.test');
            $client->request('GET', $indicateur);
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->rollback();
        }
    }

    /** Audit du 26/10/2026 : la liste répondait 500 à un point focal, et tout le projet aux autres. */
    public function testLaListeDesEngagementsSuitLaPortee(): void
    {
        [$client] = $this->demarrer();

        try {
            $montants = function (string $email) use ($client): array {
                $this->connecter($client, $email);
                $client->request('GET', '/api/v1/engagements');
                self::assertResponseIsSuccessful();

                return array_map('intval', array_column($this->corps($client)['donnees'], 'montant'));
            };

            self::assertSame([1_000_000], $montants('focal.csi@example.test'), 'La ligne du CSI, pas l\'activité de la DSID.');
            $dsid = $montants('focal.dsid@example.test');
            sort($dsid);
            self::assertSame([500_000, 2_000_000], $dsid);
            $tous = $montants('rse@example.test');
            sort($tous);
            self::assertSame([500_000, 1_000_000, 2_000_000], $tous);
        } finally {
            $this->rollback();
        }
    }

    /**
     * Décision du 26/10/2026 : le point focal n'a plus accès aux parties
     * prenantes — ni fiches, ni points focaux, ni liaison de comptes, ni
     * activités d'une structure. Il transmet seulement les rapports de sa
     * structure (« Mes rapports »). L'équipe du projet garde tout.
     */
    public function testLePointFocalNAccedePlusAuxPartiesPrenantesMaisTransmetSesRapports(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $csi = $f['structureCsi']->getUuid()->toRfc4122();
            $pointFocal = $f['pointFocalCsi']->getUuid()->toRfc4122();
            $json = ['CONTENT_TYPE' => 'application/json'];

            $this->connecter($client, 'focal.csi@example.test');
            $client->request('GET', '/api/v1/structures');
            self::assertResponseIsSuccessful('La liste des sigles reste ouverte : filtres et formulaires.');
            foreach (["/api/v1/structures/{$csi}", "/api/v1/structures/{$csi}/activites", '/api/v1/points-focaux', "/api/v1/points-focaux?structure={$csi}"] as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(403, $url);
            }
            $client->request('PATCH', "/api/v1/points-focaux/{$pointFocal}", server: $json, content: json_encode([
                'utilisateurUuid' => $f['focalCsi']->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403, 'Il ne lie plus de compte à une fiche point focal, même de sa structure.');
            $client->request('POST', '/api/v1/points-focaux', server: $json, content: json_encode([
                'structure' => $csi, 'nomComplet' => 'Nouveau', 'fonction' => 'Point focal',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);

            // Ses rapports, et eux seuls ; il transmet le sien, pas celui d'une autre structure.
            $client->request('GET', '/api/v1/rapports');
            self::assertResponseIsSuccessful();
            self::assertSame([$f['rapportCsi']->getUuid()->toRfc4122()], array_column($this->corps($client)['donnees'], 'uuid'));
            $client->request('POST', '/api/v1/rapports/'.$f['rapportCsi']->getUuid()->toRfc4122().'/transmettre', files: ['fichier' => $this->fichierRapport()]);
            self::assertResponseIsSuccessful();
            self::assertSame('transmis', $this->corps($client)['statut']);
            $client->request('POST', '/api/v1/rapports/'.$f['rapportDpee']->getUuid()->toRfc4122().'/transmettre', files: ['fichier' => $this->fichierRapport()]);
            self::assertResponseStatusCodeSame(403);

            // L'équipe du projet : tout le module.
            $this->connecter($client, 'rse@example.test');
            foreach (["/api/v1/structures/{$csi}", "/api/v1/structures/{$csi}/activites", '/api/v1/points-focaux'] as $url) {
                $client->request('GET', $url);
                self::assertResponseIsSuccessful($url);
            }
            $client->request('GET', '/api/v1/rapports');
            self::assertCount(2, $this->corps($client)['donnees']);
        } finally {
            $this->rollback();
        }
    }

    /** Tableau de bord : répartition des engagements validés par nature, la plus lourde d'abord. */
    public function testLaSyntheseRepartitLesEngagementsValidesParNature(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'rse@example.test');
            $client->request('GET', '/api/v1/projets/'.$f['projet']->getUuid()->toRfc4122().'/synthese');
            self::assertResponseIsSuccessful();
            $synthese = $this->corps($client);
            self::assertSame([
                ['nature' => 'fournitures', 'montant' => 2_000_000, 'nombre' => 1],
                ['nature' => 'travaux', 'montant' => 1_000_000, 'nombre' => 1],
            ], $synthese['engagementsParNature'], 'Le brouillon de 500 000 FCFA n\'est pas compté.');
            self::assertSame(3_000_000, $synthese['engage'], 'Même périmètre que le montant engagé.');
        } finally {
            $this->rollback();
        }
    }

    /** Décision du 26/10/2026 : le module Projets est réservé à l'UGP. */
    public function testLeModuleProjetsEstReserveALUgp(): void
    {
        [$client] = $this->demarrer();

        try {
            foreach (['focal.csi@example.test', 'lecteur@example.test'] as $email) {
                $this->connecter($client, $email);
                $client->request('GET', '/api/v1/auth/moi');
                self::assertArrayNotHasKey('initiatives.consulter', $this->corps($client)['permissionsPortee'], $email);
                foreach (['/api/v1/initiatives', '/api/v1/initiatives/responsables-eligibles'] as $url) {
                    $client->request('GET', $url);
                    self::assertResponseStatusCodeSame(403, "{$email} : {$url}");
                }
            }

            foreach (['rse@example.test', 'coord@example.test', 'gestion@example.test'] as $email) {
                $this->connecter($client, $email);
                $client->request('GET', '/api/v1/initiatives');
                self::assertResponseIsSuccessful($email);
            }

            // Seuls des comptes de l'UGP peuvent être désignés responsables.
            $client->request('GET', '/api/v1/initiatives/responsables-eligibles');
            $eligibles = array_column($this->corps($client)['donnees'], 'nomComplet');
            sort($eligibles);
            self::assertSame(['coord Test', 'gestion Test', 'rse Test'], $eligibles);
        } finally {
            $this->rollback();
        }
    }

    private function fichierRapport(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'sisep_rapport_');
        self::assertNotFalse($chemin);
        file_put_contents($chemin, 'rapport trimestriel');

        return new UploadedFile($chemin, 'rapport-T3.pdf', 'application/pdf', null, true);
    }

    public function testUnPointFocalSansStructureEstRefuse(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'coord@example.test');
            $client->request('PUT', '/api/v1/admin/utilisateurs/'.$f['focalCsi']->getUuid()->toRfc4122().'/roles', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'roles' => [['role' => 'point_focal_structure']],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'Sur toutes les structures, il modifierait toutes les activités.');
        } finally {
            $this->rollback();
        }
    }

    public function testUneComposanteCreeeApparaitAussitotDansLArbre(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $projet = $f['projet']->getUuid()->toRfc4122();
            $this->connecter($client, 'rse@example.test');
            $client->request('GET', '/api/v1/projets/'.$projet.'/arbre');
            self::assertResponseIsSuccessful();

            $client->request('POST', '/api/v1/composantes?projet='.$projet, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'code' => 'T9',
                'libelle' => 'Composante créée à l\'écran',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $client->request('GET', '/api/v1/projets/'.$projet.'/arbre');
            self::assertContains('T9', array_column($this->corps($client)['composantes'], 'code'), 'Le cache de l\'arbre est vidé à la création.');
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

        // Le catalogue de la production, à l'identique.
        $referentiel = $container->get(ReferentielApplicatif::class);
        $roles = $referentiel->semerRoles($referentiel->semerPermissions());

        $structures = [];
        foreach (['DPEE', 'CSI', 'DSID', 'UGP'] as $sigle) {
            $structures[$sigle] = new Structure($sigle.'-T', 'Structure '.$sigle, TypeStructure::DIRECTION_ANSD);
            $this->em->persist($structures[$sigle]);
        }

        $comptes = [];
        foreach ([
            'rse' => ['responsable_suivi_evaluation', null],
            'coord' => ['coordonnateur', null],
            'focal.csi' => ['point_focal_structure', 'CSI'],
            'focal.dpee' => ['point_focal_structure', 'DPEE'],
            'focal.dsid' => ['point_focal_structure', 'DSID'],
            'lecteur' => ['lecteur', null],
            'gestion' => ['gestionnaire_budgetaire', null],
        ] as $nom => [$codeRole, $sigle]) {
            $u = new Utilisateur($nom.'@example.test', 'temp', 'Test', $nom);
            $u->setMotDePasseHash($hasher->hashPassword($u, self::MOT_DE_PASSE));
            $u->marquerMdpChange();
            $this->em->persist($u);
            $this->em->persist(new UtilisateurRole($u, $roles[$codeRole], null !== $sigle ? $structures[$sigle] : null));
            $comptes[$nom] = $u;
        }

        $projet = new Projet('TESTSECT', 'Projet parcours', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, (int) date('Y'), new \DateTimeImmutable(date('Y').'-01-01'), new \DateTimeImmutable(date('Y').'-12-31'));
        $this->em->persist($exercice);
        $composante = new Composante($projet, 'T1', 'Composante parcours');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'T1.1', 'Sous-composante parcours');
        $this->em->persist($sousComposante);

        // Fiche point focal du CSI et rapports trimestriels de deux structures.
        $pointFocalCsi = new PointFocal($structures['CSI'], 'Point focal CSI', 'Chef de division');
        $this->em->persist($pointFocalCsi);
        $rapportCsi = new RapportPeriodique($structures['CSI'], $exercice, PeriodeTrimestre::T3, new \DateTimeImmutable(date('Y').'-10-15'));
        $rapportDpee = new RapportPeriodique($structures['DPEE'], $exercice, PeriodeTrimestre::T3, new \DateTimeImmutable(date('Y').'-10-15'));
        $this->em->persist($rapportCsi);
        $this->em->persist($rapportDpee);

        // Activité de la DPEE, dont une ligne est confiée au CSI — le cas de
        // 9 activités sur 26 au PTBA 2026.
        $activite = new Activite($sousComposante, 'T1.1.1', 'Activité partagée', $structures['DPEE']);
        $this->em->persist($activite);
        $ligneCsi = new SousActivite($activite, 'Ligne CSI');
        $ligneCsi->setStructureResponsable($structures['CSI']);
        $ligneDpee = new SousActivite($activite, 'Ligne DPEE');
        $this->em->persist($ligneCsi);
        $this->em->persist($ligneDpee);
        $etapes = [
            'etapeCsi1' => new EtapeActivite($activite, 'Collecte', 1, $ligneCsi),
            'etapeCsi2' => new EtapeActivite($activite, 'Rapport', 2, $ligneCsi),
            'etapeDpee' => new EtapeActivite($activite, 'Atelier', 1, $ligneDpee),
            'etapePropre' => new EtapeActivite($activite, 'Coordination', 1),
        ];
        foreach ($etapes as $etape) {
            $this->em->persist($etape);
        }

        // Un engagement sur la ligne du CSI, un autre sur une activité de la DSID.
        $activiteDsid = new Activite($sousComposante, 'T1.1.2', 'Activité DSID', $structures['DSID']);
        $this->em->persist($activiteDsid);
        foreach ([$ligneCsi, $activiteDsid] as $cible) {
            $this->em->persist(new Dotation($cible, $exercice, Montant::fromFcfa(5_000_000), $comptes['rse']));
        }
        // Validés par le coordonnateur, sauf un troisième resté en brouillon.
        foreach ([
            [$ligneCsi, 1_000_000, NatureEngagement::TRAVAUX, true],
            [$activiteDsid, 2_000_000, NatureEngagement::FOURNITURES, true],
            [$activiteDsid, 500_000, NatureEngagement::TRAVAUX, false],
        ] as [$cible, $montant, $nature, $valide]) {
            $engagement = new Engagement($cible, $exercice, $nature, Montant::fromFcfa($montant), new \DateTimeImmutable(), $comptes['rse']);
            if ($valide) {
                $engagement->valider($comptes['coord']);
            }
            $this->em->persist($engagement);
        }

        $indicateur = new Indicateur(
            $projet, 'IND-T', 'Indicateur de parcours', TypeIndicateur::IRI, 'Nombre', SensIndicateur::CROISSANT,
            '0', new \DateTimeImmutable('2025-01-01'), '10', new \DateTimeImmutable('2028-12-31'),
            FrequenceIndicateur::TRIMESTRIELLE, 'Source de test',
        );
        $this->em->persist($indicateur);

        $this->em->flush();
        // Les rôles du catalogue chargés par les requêtes HTTP qui suivent,
        // pas ceux restés en mémoire.
        $this->em->clear();

        return [$client, [
            'projet' => $projet,
            'activite' => $activite,
            'ligneDpee' => $ligneDpee,
            'indicateur' => $indicateur,
            'focalCsi' => $comptes['focal.csi'],
            'structureCsi' => $structures['CSI'],
            'pointFocalCsi' => $pointFocalCsi,
            'rapportCsi' => $rapportCsi,
            'rapportDpee' => $rapportDpee,
            ...$etapes,
        ]];
    }

    private function renseigner(KernelBrowser $client, EtapeActivite $etape, int $avancement): void
    {
        $client->request('PATCH', '/api/v1/etapes/'.$etape->getUuid()->toRfc4122(), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'avancement' => $avancement,
            'dateMiseAJour' => date('Y-m-d'),
        ], \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function valeurDeLaSerie(KernelBrowser $client, string $indicateur, string $uuid): array
    {
        $client->request('GET', $indicateur.'/valeurs');
        self::assertResponseIsSuccessful();
        foreach ($this->corps($client)['donnees'] as $valeur) {
            if ($valeur['uuid'] === $uuid) {
                return $valeur;
            }
        }
        self::fail('Valeur absente de la série.');
    }

    /** @return list<string> */
    private function activitesDeLArbre(KernelBrowser $client): array
    {
        self::assertResponseIsSuccessful();
        $uuids = [];
        foreach ($this->corps($client)['composantes'] as $c) {
            foreach ($c['sousComposantes'] as $sc) {
                foreach ($sc['activites'] as $a) {
                    $uuids[] = $a['uuid'];
                }
            }
        }

        return $uuids;
    }

    /** @return array<string, mixed> */
    private function noeudActivite(KernelBrowser $client, string $uuid): array
    {
        foreach ($this->corps($client)['composantes'] as $c) {
            foreach ($c['sousComposantes'] as $sc) {
                foreach ($sc['activites'] as $a) {
                    if ($a['uuid'] === $uuid) {
                        return $a;
                    }
                }
            }
        }
        self::fail('Activité absente de l\'arbre.');
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
