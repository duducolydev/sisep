<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
 * P2 (§ 10 du brief) : CRUD composantes/sous-composantes/activités/sous-activités.
 * Vérifie à la fois le chemin nominal (coordonnateur) et que la portée par
 * structure (§ 7.2) s'applique bien à la création, pas seulement à la
 * lecture/modification déjà couvertes en P1.
 */
final class ActiviteCrudHttpTest extends WebTestCase
{
    private EntityManagerInterface $em;

    public function testCrudCompletEtPorteeSurCreation(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        try {
            $hasher = $container->get(UserPasswordHasherInterface::class);

            $consulter = new Permission('activites.consulter', 'activites', 'Consulter');
            $creer = new Permission('activites.creer', 'activites', 'Créer');
            $modifier = new Permission('activites.modifier', 'activites', 'Modifier');
            $supprimer = new Permission('activites.supprimer', 'activites', 'Supprimer');
            foreach ([$consulter, $creer, $modifier, $supprimer] as $p) {
                $this->em->persist($p);
            }

            $roleCoordonnateur = new Role('coordonnateur_test', 'Coordonnateur test', true);
            $roleCoordonnateur->accorder($consulter);
            $roleCoordonnateur->accorder($creer);
            $roleCoordonnateur->accorder($modifier);
            $roleCoordonnateur->accorder($supprimer);
            $this->em->persist($roleCoordonnateur);

            $rolePointFocal = new Role('point_focal_test', 'Point focal test', true);
            $rolePointFocal->accorder($consulter);
            $rolePointFocal->accorder($modifier);
            $this->em->persist($rolePointFocal);

            $dsid = new Structure('DSID-CRUD', 'Direction test', TypeStructure::DIRECTION_ANSD);
            $this->em->persist($dsid);

            $motDePasse = 'MotDePasseValide123!';

            $coordonnateur = new Utilisateur('coordo.crud@example.test', 'temp', 'Test', 'Coordo');
            $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, $motDePasse));
            $coordonnateur->marquerMdpChange();
            $this->em->persist($coordonnateur);
            $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur));

            $pointFocal = new Utilisateur('focal.crud@example.test', 'temp', 'Test', 'Focal');
            $pointFocal->setStructure($dsid);
            $pointFocal->setMotDePasseHash($hasher->hashPassword($pointFocal, $motDePasse));
            $pointFocal->marquerMdpChange();
            $this->em->persist($pointFocal);
            $this->em->persist(new UtilisateurRole($pointFocal, $rolePointFocal, $dsid));

            $projet = new Projet('TESTCRUD', 'Projet CRUD', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
            $this->em->persist($projet);
            $composante = new Composante($projet, 'CC', 'Composante CRUD');
            $this->em->persist($composante);
            $sousComposante = new SousComposante($composante, 'CC.1', 'Sous-composante CRUD');
            $this->em->persist($sousComposante);

            $this->em->flush();

            // --- Le coordonnateur crée une activité pour la structure DSID ---
            $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'coordo.crud@example.test', 'motDePasse' => $motDePasse], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('POST', '/api/v1/activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'sousComposante' => $sousComposante->getUuid()->toRfc4122(),
                'code' => 'CC.1.1',
                'libelle' => 'Activité créée par le coordonnateur',
                'structureResponsable' => $dsid->getUuid()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent());
            /** @var array{uuid: string} $activiteCreee */
            $activiteCreee = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

            // --- Le coordonnateur ajoute une sous-activité ---
            $client->request('POST', '/api/v1/sous-activites', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'activite' => $activiteCreee['uuid'],
                'libelle' => 'Sous-activité créée',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);
            /** @var array{uuid: string} $sousActiviteCreee */
            $sousActiviteCreee = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

            // --- Une sous-composante ne peut pas être créée par un point focal, même scopé ---
            $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'focal.crud@example.test', 'motDePasse' => $motDePasse], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();

            $client->request('POST', '/api/v1/composantes', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'code' => 'CX',
                'libelle' => 'Tentative non autorisée',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403, 'Un point focal ne doit jamais pouvoir créer de composante, quelle que soit sa structure.');

            // --- La saisie de réalisations n'existe plus (§ 6 du CR du 22/09/2026) ---
            // L'avancement se renseigne désormais étape par étape ; la route est
            // retirée, pas seulement interdite. Voir EtapesActiviteHttpTest.
            $client->request('POST', \sprintf('/api/v1/sous-activites/%s/realisations', $sousActiviteCreee['uuid']), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'avancement' => 65,
                'dateConstat' => '2026-07-01',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(404, 'La saisie de réalisations a été retirée au profit des étapes.');

            // --- Le coordonnateur supprime (logiquement) l'activité ---
            $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'coordo.crud@example.test', 'motDePasse' => $motDePasse], \JSON_THROW_ON_ERROR));
            $client->request('DELETE', \sprintf('/api/v1/activites/%s', $activiteCreee['uuid']));
            self::assertResponseStatusCodeSame(204);

            $supprimeLe = $this->em->getConnection()->fetchOne(
                'SELECT supprime_le FROM activites WHERE uuid = ?',
                [$activiteCreee['uuid']],
            );
            self::assertNotNull($supprimeLe, 'La suppression doit être logique (supprime_le renseigné), pas une ligne DROP.');

            $activiteEnBase = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM activites WHERE uuid = ?', [$activiteCreee['uuid']]);
            self::assertSame(1, (int) $activiteEnBase, 'La ligne doit toujours exister en base après une suppression logique.');
        } finally {
            $this->em->getConnection()->rollBack();
        }
    }
}
