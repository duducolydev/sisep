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
use App\Entity\Projet;
use App\Entity\SousActivite;
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
 * Décision du 09/11/2026 : un utilisateur limité à des structures ne voit les
 * montants que de ses activités, à tous les niveaux d'agrégation. Une portée
 * globale voit tout le projet, et les composantes restent listées pour tous.
 *
 * Répartition : C1 porte A-S1 (1 000) et A-S2 (2 000) ; C2 porte B-S2 (4 000)
 * et B-LIGNE (8 000), activité de S2 dont une ligne est confiée à S1.
 */
final class PorteeTotauxBudgetHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';
    private const TOUT = 15_000;
    private const PERIMETRE_S1 = 9_000;

    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private string $projet = '';
    private string $c1 = '';
    private string $c2 = '';
    private string $sc1 = '';
    private string $sc2 = '';

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

        $s1 = new Structure('PT-S1', 'Structure 1', TypeStructure::DIRECTION_ANSD);
        $s2 = new Structure('PT-S2', 'Structure 2', TypeStructure::DIRECTION_ANSD);
        $s3 = new Structure('PT-S3', 'Structure 3', TypeStructure::DIRECTION_ANSD);
        foreach ([$s1, $s2, $s3] as $s) {
            $this->em->persist($s);
        }

        $auteur = null;
        foreach ([
            'coord' => ['coordonnateur', null],
            'focal1' => ['point_focal_structure', $s1],
            'focal3' => ['point_focal_structure', $s3],
        ] as $nom => [$code, $portee]) {
            $u = new Utilisateur($nom.'@example.test', 'temp', 'Test', $nom);
            $u->setMotDePasseHash($hasher->hashPassword($u, self::MOT_DE_PASSE));
            $u->marquerMdpChange();
            $this->em->persist($u);
            $this->em->persist(new UtilisateurRole($u, $roles[$code], $portee));
            $auteur ??= $u;
        }

        $projet = new Projet('TESTPT', 'Projet portée', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $c1 = new Composante($projet, 'PT1', 'Composante 1');
        $c2 = new Composante($projet, 'PT2', 'Composante 2');
        $sc1 = new SousComposante($c1, 'PT1.1', 'Sous-composante 1');
        $sc2 = new SousComposante($c2, 'PT2.1', 'Sous-composante 2');
        $aS1 = new Activite($sc1, 'PT1.1.1', 'Activité de S1', $s1);
        $aS2 = new Activite($sc1, 'PT1.1.2', 'Activité de S2', $s2);
        $bS2 = new Activite($sc2, 'PT2.1.1', 'Autre activité de S2', $s2);
        $bLigne = new Activite($sc2, 'PT2.1.2', 'Activité de S2, ligne confiée à S1', $s2);
        $ligne = new SousActivite($bLigne, 'Ligne confiée');
        $ligne->setStructureResponsable($s1);
        foreach ([$projet, $exercice, $c1, $c2, $sc1, $sc2, $aS1, $aS2, $bS2, $bLigne, $ligne] as $o) {
            $this->em->persist($o);
        }

        foreach ([[$aS1, 1_000], [$aS2, 2_000], [$bS2, 4_000], [$ligne, 8_000]] as [$cible, $montant]) {
            $this->em->persist(new Dotation($cible, $exercice, Montant::fromFcfa($montant), $auteur));
            // La moitié engagée, le quart dépensé : chaque total se reconnaît.
            $engagement = new Engagement($cible, $exercice, NatureEngagement::TRAVAUX, Montant::fromFcfa((int) ($montant / 2)), new \DateTimeImmutable('2026-03-01'), $auteur);
            $engagement->valider($auteur);
            $this->em->persist($engagement);
            $depense = new Depense($engagement, Montant::fromFcfa((int) ($montant / 4)), new \DateTimeImmutable('2026-03-10'), $auteur);
            $depense->valider($auteur);
            $this->em->persist($depense);
        }
        $this->em->flush();

        $this->projet = $projet->getUuid()->toRfc4122();
        $this->c1 = $c1->getUuid()->toRfc4122();
        $this->c2 = $c2->getUuid()->toRfc4122();
        $this->sc1 = $sc1->getUuid()->toRfc4122();
        $this->sc2 = $sc2->getUuid()->toRfc4122();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testUnePorteeGlobaleVoitToutLeProjet(): void
    {
        $this->connecter('coord');

        self::assertSame([3_000, 1_500, 750], $this->montants($this->ligne('composante', $this->c1)));
        self::assertSame([12_000, 6_000, 3_000], $this->montants($this->ligne('composante', $this->c2)));
        self::assertSame(self::TOUT, $this->synthese()['notifie']);
        self::assertSame(self::TOUT, $this->pluriannuel()['notifie']);
        self::assertSame(self::TOUT, $this->decaissementFinal()['prevu']);
        self::assertSame((int) (self::TOUT / 4), $this->decaissementFinal()['realise']);
    }

    public function testUnPointFocalNeVoitLesMontantsQueDeSonPerimetre(): void
    {
        $this->connecter('focal1');

        // C1 : sa seule activité. C2 : l'activité dont une ligne lui est confiée.
        self::assertSame([1_000, 500, 250], $this->montants($this->ligne('composante', $this->c1)));
        self::assertSame([8_000, 4_000, 2_000], $this->montants($this->ligne('composante', $this->c2)));
        self::assertSame([1_000, 500, 250], $this->montants($this->ligne('sous_composante', $this->sc1)));
        self::assertSame([8_000, 4_000, 2_000], $this->montants($this->ligne('sous_composante', $this->sc2)));

        $synthese = $this->synthese();
        self::assertSame([self::PERIMETRE_S1, 4_500, 2_250], $this->montants($synthese));
        self::assertSame(4_500, $synthese['disponible']);
        self::assertSame(4_500, array_sum(array_column($synthese['engagementsParNature'], 'montant')));

        $pluriannuel = $this->pluriannuel();
        self::assertSame(self::PERIMETRE_S1, $pluriannuel['notifie']);
        self::assertSame(2_250, $pluriannuel['depense']);

        self::assertSame(self::PERIMETRE_S1, $this->decaissementFinal()['prevu']);
        self::assertSame(2_250, $this->decaissementFinal()['realise']);
    }

    public function testLArbreLimiteAussiLesMontantsAgreges(): void
    {
        $this->connecter('focal1');

        $composantes = $this->lire("/api/v1/projets/{$this->projet}/arbre?exercice=2026")['composantes'];
        $parUuid = array_column($composantes, null, 'uuid');
        self::assertSame([1_000, 500, 250], $this->montants($parUuid[$this->c1]));
        self::assertSame([8_000, 4_000, 2_000], $this->montants($parUuid[$this->c2]));
        $sousComposante = array_column($parUuid[$this->c1]['sousComposantes'], null, 'uuid')[$this->sc1];
        self::assertSame([1_000, 500, 250], $this->montants($sousComposante));

        // Une clé de cache par portée : la portée globale garde ses totaux.
        $this->connecter('coord');
        $composantes = $this->lire("/api/v1/projets/{$this->projet}/arbre?exercice=2026")['composantes'];
        self::assertSame(3_000, array_column($composantes, null, 'uuid')[$this->c1]['notifie']);
    }

    public function testSansActiviteDansSonPerimetreLesComposantesRestentListeesSansMontant(): void
    {
        $this->connecter('focal3');

        $composante = $this->ligne('composante', $this->c1);
        self::assertSame($this->c1, $composante['uuid'], 'La composante reste listée pour situer le projet.');
        self::assertNull($composante['notifie'], 'Aucun montant : null, jamais 0.');
        self::assertNull($composante['tauxExecution']);

        self::assertSame(0, $this->synthese()['notifie']);
        self::assertNull($this->synthese()['tauxExecution']);
        self::assertSame(0, $this->pluriannuel()['notifie']);
    }

    /**
     * @param array<string, mixed> $ligne
     *
     * @return list<int>
     */
    private function montants(array $ligne): array
    {
        return [$ligne['notifie'], $ligne['engage'], $ligne['depense']];
    }

    /** @return array<string, mixed> */
    private function ligne(string $niveau, string $uuid): array
    {
        $lignes = $this->lire("/api/v1/budget/execution?niveau={$niveau}&exercice=2026")['donnees'];

        return array_column($lignes, null, 'uuid')[$uuid];
    }

    /** @return array<string, mixed> */
    private function synthese(): array
    {
        return $this->lire("/api/v1/projets/{$this->projet}/synthese?exercice=2026");
    }

    /** @return array<string, mixed> */
    private function pluriannuel(): array
    {
        return $this->lire("/api/v1/projets/{$this->projet}/execution-pluriannuelle")['global'];
    }

    /** @return array<string, mixed> */
    private function decaissementFinal(): array
    {
        $points = $this->lire("/api/v1/projets/{$this->projet}/decaissement?exercice=2026")['points'];

        return $points[array_key_last($points)];
    }

    /** @return array<string, mixed> */
    private function lire(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function connecter(string $qui): void
    {
        $this->client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $qui.'@example.test',
            'motDePasse' => self::MOT_DE_PASSE,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }
}
