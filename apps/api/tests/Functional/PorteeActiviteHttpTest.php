<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
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
 * Critère d'acceptation P1 (§ 10 du brief) : un point focal DPEE ne voit ni
 * ne modifie les activités de la DSID, y compris en appelant l'API
 * directement. Bout en bout via de vraies requêtes HTTP (connexion par
 * cookie de session, puis appels sur /api/v1/activites).
 */
final class PorteeActiviteHttpTest extends WebTestCase
{
    private EntityManagerInterface $em;

    public function testPointFocalDpeeNeVoitNiNeModifieLesActivitesDeLaDsid(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // garder le même EntityManager (et donc la transaction ouverte) entre les requêtes simulées

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        try {
            $hasher = $container->get(UserPasswordHasherInterface::class);

            $consulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
            $modifier = new Permission('activites.modifier', 'activites', 'Modifier une activité');
            $this->em->persist($consulter);
            $this->em->persist($modifier);

            $role = new Role('point_focal_structure', 'Point focal de structure', true);
            $role->accorder($consulter);
            $role->accorder($modifier);
            $this->em->persist($role);

            $dsid = new Structure('DSID-P1', 'Direction test', TypeStructure::DIRECTION_ANSD);
            $dpee = new Structure('DPEE-P1', 'Direction test', TypeStructure::PARTENAIRE_NATIONAL);
            $this->em->persist($dsid);
            $this->em->persist($dpee);

            $motDePasse = 'MotDePasseValide123!';
            $utilisateur = new Utilisateur('focal.dpee.p1@example.test', 'temp', 'Ba', 'Fatou');
            $utilisateur->setStructure($dpee);
            $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, $motDePasse));
            $this->em->persist($utilisateur);

            $this->em->persist(new UtilisateurRole($utilisateur, $role, $dpee));

            $projet = new Projet('TESTP1', 'Projet test P1', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
            $this->em->persist($projet);
            $composante = new Composante($projet, 'CP1', 'Composante test P1');
            $this->em->persist($composante);
            $sousComposante = new SousComposante($composante, 'CP1.1', 'Sous-composante test P1');
            $this->em->persist($sousComposante);

            $activiteDsid = new Activite($sousComposante, 'CP1.1.1', 'Activité DSID', $dsid);
            $activiteDpee = new Activite($sousComposante, 'CP1.1.2', 'Activité DPEE', $dpee);
            $this->em->persist($activiteDsid);
            $this->em->persist($activiteDpee);

            $this->em->flush();

            $uuidDsid = $activiteDsid->getUuid()->toRfc4122();
            $uuidDpee = $activiteDpee->getUuid()->toRfc4122();

            $client->request(
                'POST',
                '/api/v1/auth/connexion',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['email' => 'focal.dpee.p1@example.test', 'motDePasse' => $motDePasse], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseIsSuccessful();

            // Contrôle positif : le point focal DPEE voit sa propre activité.
            $client->request('GET', "/api/v1/activites/{$uuidDpee}");
            self::assertResponseIsSuccessful();

            // Ne la voit pas en détail...
            $client->request('GET', "/api/v1/activites/{$uuidDsid}");
            self::assertResponseStatusCodeSame(404);

            // ... ni ne la modifie...
            $client->request(
                'PATCH',
                "/api/v1/activites/{$uuidDsid}",
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['libelle' => 'Tentative de modification non autorisée'], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(404);

            // ... ni ne la voit apparaître dans la liste paginée.
            $client->request('GET', '/api/v1/activites?taille=100');
            self::assertResponseIsSuccessful();
            /** @var array{donnees: list<array{code: string}>} $corps */
            $corps = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $codes = array_column($corps['donnees'], 'code');

            self::assertContains('CP1.1.2', $codes, "Le point focal doit voir l'activité de sa propre structure dans la liste.");
            self::assertNotContains('CP1.1.1', $codes, "Le point focal ne doit pas voir l'activité DSID dans la liste, même paginée.");

            // Preuve que la modification a bien échoué en base, pas seulement en
            // apparence. Requête DBAL brute : le filtre SQL de portée reste
            // actif sur cet EntityManager (partagé avec le client de test via
            // disableReboot) et fausserait une relecture via le repository.
            $libelleEnBase = $this->em->getConnection()->fetchOne(
                'SELECT libelle FROM activites WHERE id = ?',
                [$activiteDsid->getId()],
            );
            self::assertSame('Activité DSID', $libelleEnBase);
        } finally {
            $this->em->getConnection()->rollBack();
        }
    }
}
