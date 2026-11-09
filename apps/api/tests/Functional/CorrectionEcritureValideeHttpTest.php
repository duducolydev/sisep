<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Amorcage\ReferentielApplicatif;
use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Exercice;
use App\Entity\Projet;
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
 * Décision du 09/11/2026 : une écriture validée erronée s'annule avec un motif
 * puis se ressaisit, un montant validé ne se réécrit jamais. Permission dédiée
 * (RSE et coordonnateur), avec le catalogue de rôles réel.
 */
final class CorrectionEcritureValideeHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private string $activite = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $referentiel = $container->get(ReferentielApplicatif::class);
        $roles = $referentiel->semerRoles($referentiel->semerPermissions());

        $structure = new Structure('COR-T', 'Structure correction', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);
        foreach ([
            'gestion' => 'gestionnaire_budgetaire',
            'rse' => 'responsable_suivi_evaluation',
            'coord' => 'coordonnateur',
            'admin' => 'administrateur',
        ] as $nom => $code) {
            $u = new Utilisateur($nom.'@example.test', 'temp', 'Test', $nom);
            $u->setMotDePasseHash($hasher->hashPassword($u, self::MOT_DE_PASSE));
            $u->marquerMdpChange();
            $this->em->persist($u);
            $this->em->persist(new UtilisateurRole($u, $roles[$code], null));
        }

        $projet = new Projet('TESTCOR', 'Projet correction', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $this->em->persist(new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        $composante = new Composante($projet, 'COR', 'Composante');
        $sousComposante = new SousComposante($composante, 'COR.1', 'Sous-composante');
        $activite = new Activite($sousComposante, 'COR.1.1', 'Activité', $structure);
        foreach ([$composante, $sousComposante, $activite] as $o) {
            $this->em->persist($o);
        }
        $this->em->flush();
        $this->activite = $activite->getUuid()->toRfc4122();

        $this->connecter('rse');
        self::assertSame(201, $this->poster('/api/v1/dotations', ['activite' => $this->activite, 'exercice' => 2026, 'montant' => 1_000_000])[0]);
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testUneFauteDeFrappeDuRseSeCorrigeEtLesTotauxSuivent(): void
    {
        $fautif = $this->engagement(900_000);
        self::assertSame(900_000, $this->montantEngage());

        [$statut, $corps] = $this->poster("/api/v1/engagements/{$fautif}/annuler-validee", ['motif' => '  Faute de frappe : 90 000 et non 900 000  ']);
        self::assertSame(200, $statut);
        self::assertSame('annule', $corps['statut']);
        self::assertSame('Faute de frappe : 90 000 et non 900 000', $corps['motifAnnulation']);
        self::assertSame(0, $this->montantEngage(), 'Annulé, il sort des totaux.');

        // La ligne reste en base, sa validation aussi : rien n'est effacé.
        $ligne = $this->em->getConnection()->fetchAssociative('SELECT montant, valide_le, valide_par_id FROM engagements WHERE uuid = ?', [$fautif]);
        self::assertNotFalse($ligne);
        self::assertSame(900_000, (int) $ligne['montant']);
        self::assertNotNull($ligne['valide_le']);
        self::assertNotNull($ligne['valide_par_id']);

        $journal = $this->em->getConnection()->fetchOne(
            "SELECT j.apres FROM journal_activite j JOIN engagements e ON e.id = j.entite_id
             WHERE j.entite_type = 'engagement' AND j.action = 'modification' AND e.uuid = ?",
            [$fautif],
        );
        self::assertNotFalse($journal, 'Une annulation est tracée au journal.');
        $apres = json_decode((string) $journal, true);
        self::assertSame('annule', $apres['statut']);
        self::assertStringContainsString('Faute de frappe', $apres['motifAnnulation']);

        // La place est libre pour la bonne écriture, y compris jusqu'au plafond.
        $this->engagement(1_000_000);
        self::assertSame(1_000_000, $this->montantEngage());
    }

    public function testUneDepenseValideeSeCorrigeEtRendSonPlafond(): void
    {
        $engagement = $this->engagement(100_000);
        $fautive = $this->depense($engagement, 100_000);
        self::assertSame(422, $this->poster('/api/v1/depenses', ['engagement' => $engagement, 'montant' => 1, 'dateDepense' => '2026-04-01'])[0], 'Engagement entièrement décaissé.');

        [$statut, $corps] = $this->poster("/api/v1/depenses/{$fautive}/annuler-validee", ['motif' => 'Mauvais montant']);
        self::assertSame(200, $statut);
        self::assertSame('annule', $corps['statut']);
        self::assertSame('Mauvais montant', $corps['motifAnnulation']);
        self::assertSame(0, $this->montantDepense());

        $this->depense($engagement, 40_000);
        self::assertSame(40_000, $this->montantDepense());
    }

    public function testUnEngagementQuiPorteDesDepensesNeSAnnulePasAvantElles(): void
    {
        $engagement = $this->engagement(100_000);
        $depense = $this->depense($engagement, 10_000);

        [$statut, $corps] = $this->poster("/api/v1/engagements/{$engagement}/annuler-validee", ['motif' => 'Erreur']);
        self::assertSame(422, $statut);
        self::assertStringContainsString('annulez-les', $corps['detail']);

        self::assertSame(200, $this->poster("/api/v1/depenses/{$depense}/annuler-validee", ['motif' => 'Erreur'])[0]);
        self::assertSame(200, $this->poster("/api/v1/engagements/{$engagement}/annuler-validee", ['motif' => 'Erreur'])[0], 'Sa dépense annulée, il peut l\'être.');
    }

    public function testLeMotifEstObligatoire(): void
    {
        $engagement = $this->engagement(10);
        $depense = $this->depense($engagement, 1);

        foreach ([['motif' => ''], ['motif' => '   '], []] as $corps) {
            self::assertSame(422, $this->poster("/api/v1/engagements/{$engagement}/annuler-validee", $corps)[0]);
            self::assertSame(422, $this->poster("/api/v1/depenses/{$depense}/annuler-validee", $corps)[0]);
        }
        self::assertSame(422, $this->poster("/api/v1/depenses/{$depense}/annuler-validee", ['motif' => str_repeat('m', 1001)])[0]);
    }

    public function testSeulsLeRseEtLeCoordonnateurPeuventCorriger(): void
    {
        $engagement = $this->engagement(10);
        $depense = $this->depense($engagement, 1);

        foreach (['gestion', 'admin'] as $sansDroit) {
            $this->connecter($sansDroit);
            self::assertSame(403, $this->poster("/api/v1/depenses/{$depense}/annuler-validee", ['motif' => 'x'])[0], "$sansDroit ne corrige pas une dépense.");
            self::assertSame(403, $this->poster("/api/v1/engagements/{$engagement}/annuler-validee", ['motif' => 'x'])[0], "$sansDroit ne corrige pas un engagement.");
        }

        $this->connecter('coord');
        self::assertSame(200, $this->poster("/api/v1/depenses/{$depense}/annuler-validee", ['motif' => 'x'])[0]);
        self::assertSame(200, $this->poster("/api/v1/engagements/{$engagement}/annuler-validee", ['motif' => 'x'])[0]);
    }

    public function testSeuleUneEcritureValideePasseParCetteAnnulation(): void
    {
        $this->connecter('gestion');
        [, $brouillon] = $this->poster('/api/v1/engagements', $this->corpsEngagement(10));
        $this->connecter('coord');
        self::assertSame(422, $this->poster("/api/v1/engagements/{$brouillon['uuid']}/annuler-validee", ['motif' => 'x'])[0], 'Un brouillon s\'annule sans motif, par l\'action habituelle.');

        $this->connecter('rse');
        $engagement = $this->engagement(10);
        self::assertSame(200, $this->poster("/api/v1/engagements/{$engagement}/annuler-validee", ['motif' => 'x'])[0]);
        self::assertSame(422, $this->poster("/api/v1/engagements/{$engagement}/annuler-validee", ['motif' => 'encore'])[0], 'Une annulation ne se rejoue pas et garde son motif.');
        self::assertSame('x', $this->em->getConnection()->fetchOne('SELECT motif_annulation FROM engagements WHERE uuid = ?', [$engagement]));
    }

    public function testUnExerciceClotureNeSeCorrigePlus(): void
    {
        $engagement = $this->engagement(10);
        $depense = $this->depense($engagement, 1);

        $this->em->getConnection()->executeStatement("UPDATE exercices SET statut = 'cloture' WHERE annee = 2026");
        $this->em->clear();

        self::assertSame(422, $this->poster("/api/v1/depenses/{$depense}/annuler-validee", ['motif' => 'x'])[0]);
        self::assertSame(422, $this->poster("/api/v1/engagements/{$engagement}/annuler-validee", ['motif' => 'x'])[0]);
    }

    private function engagement(int $montant): string
    {
        [$statut, $corps] = $this->poster('/api/v1/engagements', $this->corpsEngagement($montant));
        self::assertSame(201, $statut);
        self::assertSame('valide', $corps['statut'], 'Saisi par le RSE, il est validé d\'emblée.');

        return $corps['uuid'];
    }

    private function depense(string $engagement, int $montant): string
    {
        [$statut, $corps] = $this->poster('/api/v1/depenses', ['engagement' => $engagement, 'montant' => $montant, 'dateDepense' => '2026-04-01']);
        self::assertSame(201, $statut);
        self::assertSame('valide', $corps['statut']);

        return $corps['uuid'];
    }

    /** @return array<string, mixed> */
    private function corpsEngagement(int $montant): array
    {
        return ['activite' => $this->activite, 'exercice' => 2026, 'nature' => 'autre_fonctionnement', 'montant' => $montant, 'dateEngagement' => '2026-03-01'];
    }

    private function montantEngage(): int
    {
        return (int) $this->ligneBudget()['engage'];
    }

    private function montantDepense(): int
    {
        return (int) $this->ligneBudget()['depense'];
    }

    /** @return array<string, mixed> */
    private function ligneBudget(): array
    {
        $this->client->request('GET', '/api/v1/budget/execution?niveau=activite&exercice=2026');
        self::assertResponseIsSuccessful();
        $corps = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return array_column($corps['donnees'], null, 'uuid')[$this->activite];
    }

    /**
     * @param array<string, mixed>|null $corps
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function poster(string $url, ?array $corps = null): array
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: null === $corps ? null : json_encode($corps, \JSON_THROW_ON_ERROR));
        $reponse = $this->client->getResponse();
        $json = json_decode((string) $reponse->getContent(), true);

        return [$reponse->getStatusCode(), \is_array($json) ? $json : []];
    }

    private function connecter(string $qui): void
    {
        $this->poster('/api/v1/auth/connexion', ['email' => $qui.'@example.test', 'motDePasse' => self::MOT_DE_PASSE]);
        self::assertResponseIsSuccessful();
    }
}
