<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\EtapeActivite;
use App\Entity\Exercice;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\NatureEngagement;
use App\Enum\TypeStructure;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Critère d'acceptation P2 (§ 10 du brief) : l'arbre affiche les mêmes
 * agrégats que les données sous-jacentes, au franc près. Fixtures
 * volontairement petites et déterministes plutôt que le jeu de démo complet
 * — plus simple à vérifier à la main.
 */
final class ArbreAgregatsHttpTest extends WebTestCase
{
    private EntityManagerInterface $em;

    public function testLesAgregatsDeLarbreCorrespondentALaSommeDesActivites(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        try {
            $hasher = $container->get(UserPasswordHasherInterface::class);

            $consulter = new Permission('activites.consulter', 'activites', 'Consulter');
            $this->em->persist($consulter);
            $role = new Role('lecteur_test', 'Lecteur test', true);
            $role->accorder($consulter);
            $this->em->persist($role);

            $structure = new Structure('TST-ARB', 'Structure de test', TypeStructure::DIRECTION_ANSD);
            $this->em->persist($structure);

            $motDePasse = 'MotDePasseValide123!';
            $utilisateur = new Utilisateur('lecteur.arbre@example.test', 'temp', 'Test', 'Lecteur');
            $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, $motDePasse));
            $utilisateur->marquerMdpChange();
            $this->em->persist($utilisateur);
            $this->em->persist(new UtilisateurRole($utilisateur, $role));

            $projet = new Projet('TESTARBRE', 'Projet arbre', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
            $this->em->persist($projet);
            $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
            $this->em->persist($exercice);

            $composante = new Composante($projet, 'CA', 'Composante arbre');
            $this->em->persist($composante);

            $sc1 = new SousComposante($composante, 'CA.1', 'Sous-composante 1');
            $sc2 = new SousComposante($composante, 'CA.2', 'Sous-composante 2');
            $this->em->persist($sc1);
            $this->em->persist($sc2);

            // sc1 : deux activités de 1 000 000 / 600 000 / 400 000 chacune.
            // sc2 : une activité de 2 000 000 / 1 000 000 / 500 000.
            $activite1 = $this->creerActiviteAvecBudget($sc1, 'CA.1.1', $structure, $exercice, $utilisateur, 1_000_000, 600_000, 400_000);
            $activite2 = $this->creerActiviteAvecBudget($sc1, 'CA.1.2', $structure, $exercice, $utilisateur, 1_000_000, 600_000, 400_000);
            $activite3 = $this->creerActiviteAvecBudget($sc2, 'CA.2.1', $structure, $exercice, $utilisateur, 2_000_000, 1_000_000, 500_000);

            $sousActivite = new SousActivite($activite1, 'Sous-activité test');
            $this->em->persist($sousActivite);
            // L'avancement vient des étapes (§ 6 du CR du 22/09/2026) : deux
            // étapes à 60 % et 20 % donnent 40 % de progression d'activité.
            $etape1 = new EtapeActivite($activite1, 'Étape 1', 1);
            $etape1->renseigner(60, null, null, new \DateTimeImmutable('2026-06-01'));
            $etape2 = new EtapeActivite($activite1, 'Étape 2', 2);
            $etape2->renseigner(20, null, null, new \DateTimeImmutable('2026-06-01'));
            $this->em->persist($etape1);
            $this->em->persist($etape2);

            $this->em->flush();

            $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'lecteur.arbre@example.test', 'motDePasse' => $motDePasse], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('GET', \sprintf('/api/v1/projets/%s/arbre?exercice=2026&niveaux=4', $projet->getUuid()->toRfc4122()));
            self::assertResponseIsSuccessful();

            /** @var array{composantes: list<array<string, mixed>>} $corps */
            $corps = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $noeudComposante = $this->trouverParUuid($corps['composantes'], $composante->getUuid()->toRfc4122());

            self::assertSame(4_000_000, $noeudComposante['notifie'], 'La composante doit sommer les trois activités (1M + 1M + 2M).');
            self::assertSame(2_200_000, $noeudComposante['engage']);
            self::assertSame(1_300_000, $noeudComposante['depense']);

            $noeudSc1 = $this->trouverParUuid($noeudComposante['sousComposantes'], $sc1->getUuid()->toRfc4122());
            $noeudSc2 = $this->trouverParUuid($noeudComposante['sousComposantes'], $sc2->getUuid()->toRfc4122());

            self::assertSame(2_000_000, $noeudSc1['notifie'], 'sc1 doit sommer ses deux activités (1M + 1M), pas celles de sc2.');
            self::assertSame(2_000_000, $noeudSc2['notifie']);

            $noeudActivite1 = $this->trouverParUuid($noeudSc1['activites'], $activite1->getUuid()->toRfc4122());
            self::assertSame(1_000_000, $noeudActivite1['notifie']);
            self::assertSame(600_000, $noeudActivite1['engage']);
            self::assertSame(400_000, $noeudActivite1['depense']);
            self::assertSame(40, $noeudActivite1['avancement'], "L'avancement de l'activité doit être la moyenne de ses étapes.");
            self::assertCount(1, $noeudActivite1['sousActivites']);

            unset($activite2, $activite3);
        } finally {
            $this->em->getConnection()->rollBack();
        }
    }

    private function creerActiviteAvecBudget(
        SousComposante $sousComposante,
        string $code,
        Structure $structure,
        Exercice $exercice,
        Utilisateur $utilisateur,
        int $notifie,
        int $engage,
        int $depense,
    ): Activite {
        $activite = new Activite($sousComposante, $code, "Activité {$code}", $structure);
        $this->em->persist($activite);

        $this->em->persist(new Dotation($activite, $exercice, Montant::fromFcfa($notifie), $utilisateur));

        $engagement = new Engagement(
            $activite,
            $exercice,
            NatureEngagement::AUTRE_FONCTIONNEMENT,
            Montant::fromFcfa($engage),
            new \DateTimeImmutable('2026-03-01'),
            $utilisateur,
        );
        $engagement->valider($utilisateur);
        $this->em->persist($engagement);

        $depenseEntite = new Depense($engagement, Montant::fromFcfa($depense), new \DateTimeImmutable('2026-06-01'), $utilisateur);
        $depenseEntite->valider($utilisateur);
        $this->em->persist($depenseEntite);

        return $activite;
    }

    /**
     * @param list<array<string, mixed>> $noeuds
     *
     * @return array<string, mixed>
     */
    private function trouverParUuid(array $noeuds, string $uuid): array
    {
        foreach ($noeuds as $noeud) {
            if ($noeud['uuid'] === $uuid) {
                return $noeud;
            }
        }

        self::fail("Aucun nœud avec l'uuid {$uuid} trouvé dans la réponse.");
    }
}
