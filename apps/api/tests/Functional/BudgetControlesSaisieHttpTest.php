<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Amorcage\ReferentielApplicatif;
use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Exercice;
use App\Entity\Projet;
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
 * Contrôles de la chaîne budgétaire issus de l'audit du 09/11/2026, avec le
 * catalogue de rôles réel : révision d'une dotation, double comptage, dates
 * hors exercice, archivage d'une ligne budgétée, valeurs de saisie invalides.
 */
final class BudgetControlesSaisieHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private string $lignes = '';
    private string $sansLigne = '';
    private string $ligne1 = '';
    private string $ligne2 = '';

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

        $structure = new Structure('CTL-T', 'Structure contrôles', TypeStructure::DIRECTION_ANSD);
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

        $projet = new Projet('TESTCTL', 'Projet contrôles', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $this->em->persist(new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        $composante = new Composante($projet, 'CTL', 'Composante');
        $sousComposante = new SousComposante($composante, 'CTL.1', 'Sous-composante');
        $activiteLignes = new Activite($sousComposante, 'CTL.1.1', 'Activité à lignes', $structure);
        $activiteSansLigne = new Activite($sousComposante, 'CTL.1.2', 'Activité sans ligne', $structure);
        $ligne1 = new SousActivite($activiteLignes, 'Ligne 1');
        $ligne2 = new SousActivite($activiteLignes, 'Ligne 2');
        foreach ([$composante, $sousComposante, $activiteLignes, $activiteSansLigne, $ligne1, $ligne2] as $o) {
            $this->em->persist($o);
        }
        $this->em->flush();

        $this->lignes = $activiteLignes->getUuid()->toRfc4122();
        $this->sansLigne = $activiteSansLigne->getUuid()->toRfc4122();
        $this->ligne1 = $ligne1->getUuid()->toRfc4122();
        $this->ligne2 = $ligne2->getUuid()->toRfc4122();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testUneRevisionChangeLePlafondEtResteAuJournal(): void
    {
        $this->connecter('gestion');
        $dotation = $this->dotation(['activite' => $this->sansLigne], 1_000_000);
        $engagement = $this->engagement(['activite' => $this->sansLigne], 1_500_000);

        $this->connecter('coord');
        self::assertSame(422, $this->poster("/api/v1/engagements/{$engagement}/valider")[0], 'Au-delà de la dotation initiale.');

        $this->connecter('rse');
        [$statut, $corps] = $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => 2_000_000, 'motif' => 'Budget révisé en mars', 'montantValideCopil' => 1_800_000]);
        self::assertSame(200, $statut);
        self::assertSame(2_000_000, $corps['montant']);
        self::assertSame('Budget révisé en mars', $corps['commentaireRevision']);
        self::assertSame(1_800_000, $corps['montantValideCopil']);

        $this->connecter('coord');
        self::assertSame(200, $this->poster("/api/v1/engagements/{$engagement}/valider")[0], 'Le nouveau montant fait plafond.');

        $journal = $this->em->getConnection()->fetchAssociative(
            "SELECT avant, apres FROM journal_activite j JOIN dotations d ON d.id = j.entite_id
             WHERE j.entite_type = 'dotation' AND j.action = 'modification' AND d.uuid = ?",
            [$dotation],
        );
        self::assertNotFalse($journal, 'Une révision est tracée au journal.');
        self::assertSame(1_000_000, json_decode((string) $journal['avant'], true)['montant']);
        self::assertSame(2_000_000, json_decode((string) $journal['apres'], true)['montant']);
    }

    public function testUneRevisionNePeutPasDescendreSousLEngage(): void
    {
        $this->connecter('rse');
        $dotation = $this->dotation(['activite' => $this->sansLigne], 1_000_000);
        $this->engagement(['activite' => $this->sansLigne], 600_000);

        [$statut, $corps] = $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => 500_000, 'motif' => 'Baisse']);
        self::assertSame(422, $statut);
        self::assertStringContainsString('déjà engagés', $corps['detail']);

        self::assertSame(200, $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => 600_000, 'motif' => 'Au plus juste'])[0], 'Le montant exactement engagé est permis.');
    }

    public function testUneRevisionExigeUnMotifEtUnDroitDedie(): void
    {
        $this->connecter('rse');
        $dotation = $this->dotation(['activite' => $this->sansLigne], 1_000_000);

        self::assertSame(422, $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => 5, 'motif' => ''])[0], 'Motif vide.');
        self::assertSame(422, $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => 5])[0], 'Motif absent.');
        self::assertSame(422, $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => -5, 'motif' => 'x'])[0], 'Montant négatif.');

        foreach (['gestion', 'admin'] as $sansDroit) {
            $this->connecter($sansDroit);
            self::assertSame(403, $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => 5, 'motif' => 'x'])[0], "$sansDroit ne révise pas.");
        }

        $this->connecter('coord');
        self::assertSame(200, $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => 5, 'motif' => 'x'])[0], 'Le coordonnateur révise.');
        self::assertSame(404, $this->poster('/api/v1/dotations/00000000-0000-4000-8000-000000000000/reviser', ['montant' => 5, 'motif' => 'x'])[0]);
    }

    public function testLePlafondResteLeNotifieQuelQueSoitLeCopil(): void
    {
        $this->connecter('gestion');
        $this->dotation(['activite' => $this->sansLigne], 1_000_000, ['montantValideCopil' => 500_000]);
        $engagement = $this->engagement(['activite' => $this->sansLigne], 800_000);

        $this->connecter('coord');
        self::assertSame(200, $this->poster("/api/v1/engagements/{$engagement}/valider")[0], 'Décision du 09/11/2026 : le COPIL est une référence, pas un plafond.');
    }

    public function testUneActiviteEtSesLignesNeSontPasDoteesEnsemble(): void
    {
        $this->connecter('gestion');
        $this->dotation(['sousActivite' => $this->ligne1], 1_000_000);

        [$statut, $corps] = $this->poster('/api/v1/dotations', ['activite' => $this->lignes, 'exercice' => 2026, 'montant' => 1_000_000]);
        self::assertSame(422, $statut);
        self::assertStringContainsString('dotée par ses lignes', $corps['detail']);

        self::assertSame(201, $this->poster('/api/v1/dotations', ['sousActivite' => $this->ligne2, 'exercice' => 2026, 'montant' => 5])[0], 'Une autre ligne reste dotable.');
    }

    public function testUneLigneNEstPasDoteeSiSonActiviteLEst(): void
    {
        $this->connecter('gestion');
        $this->dotation(['activite' => $this->lignes], 1_000_000);

        [$statut, $corps] = $this->poster('/api/v1/dotations', ['sousActivite' => $this->ligne1, 'exercice' => 2026, 'montant' => 1_000_000]);
        self::assertSame(422, $statut);
        self::assertStringContainsString('compterait deux fois', $corps['detail']);
    }

    public function testLesDatesDoiventTomberDansLExercice(): void
    {
        $this->connecter('rse');
        $this->dotation(['activite' => $this->sansLigne], 1_000_000);

        foreach (['2025-12-31', '2027-01-01'] as $date) {
            [$statut, $corps] = $this->poster('/api/v1/engagements', $this->corpsEngagement(['activite' => $this->sansLigne], 10, ['dateEngagement' => $date]));
            self::assertSame(422, $statut, "Engagement daté du $date.");
            self::assertStringContainsString('exercice 2026', $corps['title']);
        }
        $engagement = $this->engagement(['activite' => $this->sansLigne], 100, ['dateEngagement' => '2026-01-01']);
        $this->engagement(['activite' => $this->sansLigne], 100, ['dateEngagement' => '2026-12-31']);

        foreach (['2025-12-31', '2027-01-01'] as $date) {
            [$statut, $corps] = $this->poster('/api/v1/depenses', ['engagement' => $engagement, 'montant' => 1, 'dateDepense' => $date]);
            self::assertSame(422, $statut, "Dépense datée du $date.");
            self::assertStringContainsString('exercice 2026', $corps['title']);
        }
        foreach (['2026-01-01', '2026-12-31'] as $date) {
            self::assertSame(201, $this->poster('/api/v1/depenses', ['engagement' => $engagement, 'montant' => 1, 'dateDepense' => $date])[0], "Le $date est dans l'exercice.");
        }
    }

    public function testUneLigneBudgeteeNeSArchivePas(): void
    {
        $this->connecter('gestion');
        $this->dotation(['sousActivite' => $this->ligne1], 1_000_000);

        $this->connecter('coord');
        $this->client->request('DELETE', "/api/v1/sous-activites/{$this->ligne1}");
        self::assertResponseStatusCodeSame(409);

        // Sans dotation et avec seulement un engagement annulé, plus rien ne la retient.
        $this->connecter('gestion');
        $engagement = $this->engagement(['sousActivite' => $this->ligne2], 10);
        $this->connecter('coord');
        $this->client->request('DELETE', "/api/v1/sous-activites/{$this->ligne2}");
        self::assertResponseStatusCodeSame(409, 'Un engagement en brouillon retient la ligne.');
        self::assertSame(200, $this->poster("/api/v1/engagements/{$engagement}/annuler")[0]);
        $this->client->request('DELETE', "/api/v1/sous-activites/{$this->ligne2}");
        self::assertResponseStatusCodeSame(204);
    }

    public function testLesReferencesSontBornéesEtUneReferenceVideEstNulle(): void
    {
        $this->connecter('rse');
        $this->dotation(['activite' => $this->sansLigne], 1_000_000);

        self::assertSame(422, $this->poster('/api/v1/engagements', $this->corpsEngagement(['activite' => $this->sansLigne], 10, ['referenceMarche' => str_repeat('R', 81)]))[0]);
        $vide = $this->engagement(['activite' => $this->sansLigne], 10, ['referenceMarche' => '   ']);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT reference_marche FROM engagements WHERE uuid = ?', [$vide]), 'Vide, ce n\'est pas une référence.');
        $this->engagement(['activite' => $this->sansLigne], 10, ['referenceMarche' => str_repeat('R', 80)]);

        self::assertSame(422, $this->poster('/api/v1/depenses', ['engagement' => $vide, 'montant' => 1, 'dateDepense' => '2026-03-02', 'referencePaiement' => str_repeat('R', 81)])[0]);
        [$statut, $corps] = $this->poster('/api/v1/depenses', ['engagement' => $vide, 'montant' => 1, 'dateDepense' => '2026-03-02', 'referencePaiement' => '']);
        self::assertSame(201, $statut);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT reference_paiement FROM depenses WHERE uuid = ?', [$corps['uuid']]));
    }

    public function testLesValeursMalFormeesSontRefuseesSansErreurInterne(): void
    {
        $this->connecter('rse');
        $this->dotation(['activite' => $this->sansLigne], 1_000_000);
        $engagement = $this->engagement(['activite' => $this->sansLigne], 1_000);

        foreach ([100.5, '1000', 'abc'] as $montant) {
            self::assertSame(422, $this->poster('/api/v1/engagements', $this->corpsEngagement(['activite' => $this->sansLigne], $montant))[0], 'Engagement, montant '.json_encode($montant));
            self::assertSame(422, $this->poster('/api/v1/depenses', ['engagement' => $engagement, 'montant' => $montant, 'dateDepense' => '2026-03-02'])[0], 'Dépense, montant '.json_encode($montant));
            self::assertSame(422, $this->poster('/api/v1/dotations', ['sousActivite' => $this->ligne1, 'exercice' => 2026, 'montant' => $montant])[0], 'Dotation, montant '.json_encode($montant));
        }
        foreach (['pas-une-date', '2026-02-31'] as $date) {
            self::assertSame(422, $this->poster('/api/v1/engagements', $this->corpsEngagement(['activite' => $this->sansLigne], 10, ['dateEngagement' => $date]))[0], "Engagement daté $date");
            self::assertSame(422, $this->poster('/api/v1/depenses', ['engagement' => $engagement, 'montant' => 1, 'dateDepense' => $date])[0], "Dépense datée $date");
        }

        $this->client->request('POST', '/api/v1/engagements', server: ['CONTENT_TYPE' => 'application/json'], content: '{pas du json');
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnEngagementAnnuleNeRecoitPlusDeDepense(): void
    {
        $this->connecter('gestion');
        $engagement = $this->engagement(['activite' => $this->sansLigne], 10);
        $this->connecter('coord');
        $this->poster("/api/v1/engagements/{$engagement}/annuler");

        $this->connecter('gestion');
        self::assertSame(422, $this->poster('/api/v1/depenses', ['engagement' => $engagement, 'montant' => 1, 'dateDepense' => '2026-03-02'])[0]);
    }

    public function testUnExerciceClotureNAccepteNiRevisionNiAnnulation(): void
    {
        $this->connecter('gestion');
        $dotation = $this->dotation(['activite' => $this->sansLigne], 1_000_000);
        $engagementBrouillon = $this->engagement(['activite' => $this->sansLigne], 10);
        $this->connecter('rse');
        $engagementValide = $this->engagement(['activite' => $this->sansLigne], 10);
        $this->connecter('gestion');
        [, $depense] = $this->poster('/api/v1/depenses', ['engagement' => $engagementValide, 'montant' => 1, 'dateDepense' => '2026-03-02']);

        $this->em->getConnection()->executeStatement("UPDATE exercices SET statut = 'cloture' WHERE annee = 2026");
        $this->em->clear();

        $this->connecter('coord');
        self::assertSame(422, $this->poster("/api/v1/dotations/{$dotation}/reviser", ['montant' => 5, 'motif' => 'x'])[0]);
        self::assertSame(422, $this->poster("/api/v1/engagements/{$engagementBrouillon}/annuler")[0]);
        self::assertSame(422, $this->poster("/api/v1/depenses/{$depense['uuid']}/annuler")[0]);
    }

    /**
     * @param array<string, string> $cible
     * @param array<string, mixed>  $plus
     */
    private function dotation(array $cible, int $montant, array $plus = []): string
    {
        [$statut, $corps] = $this->poster('/api/v1/dotations', $cible + ['exercice' => 2026, 'montant' => $montant] + $plus);
        self::assertSame(201, $statut);

        return $corps['uuid'];
    }

    /**
     * @param array<string, string> $cible
     * @param array<string, mixed>  $plus
     */
    private function engagement(array $cible, int $montant, array $plus = []): string
    {
        [$statut, $corps] = $this->poster('/api/v1/engagements', $this->corpsEngagement($cible, $montant, $plus));
        self::assertSame(201, $statut);

        return $corps['uuid'];
    }

    /**
     * @param array<string, string> $cible
     * @param array<string, mixed>  $plus
     *
     * @return array<string, mixed>
     */
    private function corpsEngagement(array $cible, mixed $montant, array $plus = []): array
    {
        return $plus + $cible + ['exercice' => 2026, 'nature' => 'autre_fonctionnement', 'montant' => $montant, 'dateEngagement' => '2026-03-01'];
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
