<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Exercice;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * GET /projets/:uuid/activite-recente (§ 2.1 du brief) : fil des dernières
 * écritures. Vérifie surtout que la clause LIMIT paramétrée fonctionne — un
 * paramètre lié en chaîne au lieu d'un entier fait échouer la requête en
 * base (MySQL n'accepte pas '?' lié comme chaîne dans un LIMIT).
 */
final class ActiviteRecenteHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLeFilAfficheLesEcrituresRecentesDuProjet(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        try {
            $hasher = $container->get(UserPasswordHasherInterface::class);

            $permSaisir = new Permission('budget.engagement.saisir', 'budget', 'Saisir un engagement');
            $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
            $this->em->persist($permSaisir);
            $this->em->persist($permConsulter);

            $role = new Role('gestionnaire_test_recent', 'Gestionnaire test', true);
            $role->accorder($permSaisir);
            $role->accorder($permConsulter);
            $this->em->persist($role);

            $structure = new Structure('TST-REC', 'Structure de test', TypeStructure::DIRECTION_ANSD);
            $this->em->persist($structure);

            $utilisateur = new Utilisateur('gestionnaire.recent@example.test', 'temp', 'Test', 'Gestionnaire');
            $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));
            $utilisateur->marquerMdpChange();
            $this->em->persist($utilisateur);
            $this->em->persist(new UtilisateurRole($utilisateur, $role, null));

            $projet = new Projet('TESTRECENT', 'Projet activité récente', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
            $this->em->persist($projet);
            $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
            $this->em->persist($exercice);
            $composante = new Composante($projet, 'CR', 'Composante activité récente');
            $this->em->persist($composante);
            $sousComposante = new SousComposante($composante, 'CR.1', 'Sous-composante activité récente');
            $this->em->persist($sousComposante);
            $activite = new Activite($sousComposante, 'CR.1.1', 'Activité récente', $structure);
            $this->em->persist($activite);

            $this->em->flush();

            $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'gestionnaire.recent@example.test', 'motDePasse' => self::MOT_DE_PASSE], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('POST', '/api/v1/dotations', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $activite->getUuid()->toRfc4122(),
                'exercice' => $exercice->getAnnee(),
                'montant' => 1_000_000,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $client->request('GET', \sprintf('/api/v1/projets/%s/activite-recente', $projet->getUuid()->toRfc4122()));
            self::assertResponseIsSuccessful();

            /** @var array{evenements: list<array<string, mixed>>} $corps */
            $corps = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $evenement = current(array_filter($corps['evenements'], static fn (array $e) => 'dotation' === $e['type']));

            self::assertNotFalse($evenement, 'La dotation saisie doit apparaître dans le fil.');
            self::assertSame('CR.1.1', $evenement['code']);
        } finally {
            $this->em->getConnection()->rollBack();
        }
    }
}
