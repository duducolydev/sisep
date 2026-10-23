<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Dotation;
use App\Entity\Exercice;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\TypeStructure;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gestion de l'arbre du PTBA depuis l'interface (présentation au PHASAOC,
 * octobre 2026) : l'archivage ne fait jamais disparaître un budget ni des
 * éléments enfants, et changer la structure responsable d'une activité
 * relève de l'UGP.
 */
final class StructurePtbaHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLArchivageRespecteLesEnfantsEtLeBudget(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'ugp.ptba@example.test');

            $client->request('DELETE', '/api/v1/composantes/'.$f['composante']->getUuid()->toRfc4122());
            self::assertResponseStatusCodeSame(409, 'Une composante qui contient des sous-composantes ne s\'archive pas.');

            $client->request('DELETE', '/api/v1/sous-composantes/'.$f['sousComposante']->getUuid()->toRfc4122());
            self::assertResponseStatusCodeSame(409, 'Une sous-composante qui contient des activités ne s\'archive pas.');

            $client->request('DELETE', '/api/v1/activites/'.$f['budgetee']->getUuid()->toRfc4122());
            self::assertResponseStatusCodeSame(409, 'Une activité dotée ne s\'archive pas.');

            $client->request('DELETE', '/api/v1/activites/'.$f['vide']->getUuid()->toRfc4122());
            self::assertResponseStatusCodeSame(204, 'Une activité sans budget s\'archive.');
        } finally {
            $this->rollback();
        }
    }

    public function testChangerLaStructureResponsableRelevantDeLUgp(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $url = '/api/v1/activites/'.$f['vide']->getUuid()->toRfc4122();
            $corps = json_encode(['structureResponsable' => $f['autre']->getUuid()->toRfc4122()], \JSON_THROW_ON_ERROR);

            // Le point focal renseigne son activité, il ne la confie pas à une autre structure.
            $this->connecter($client, 'focal.ptba@example.test');
            $client->request('PATCH', $url, server: ['CONTENT_TYPE' => 'application/json'], content: $corps);
            self::assertResponseStatusCodeSame(403);

            $this->connecter($client, 'ugp.ptba@example.test');
            $client->request('PATCH', $url, server: ['CONTENT_TYPE' => 'application/json'], content: $corps);
            self::assertResponseIsSuccessful();
            self::assertSame('PTB-B', json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['structureResponsable']['sigle']);
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

        $permissions = [];
        foreach (['activites.consulter', 'activites.creer', 'activites.modifier', 'activites.supprimer'] as $code) {
            $permissions[$code] = new Permission($code, 'activites', $code);
            $this->em->persist($permissions[$code]);
        }
        $ugp = new Role('ugp_ptba_test', 'UGP', true);
        foreach ($permissions as $permission) {
            $ugp->accorder($permission);
        }
        $this->em->persist($ugp);
        $focal = new Role('focal_ptba_test', 'Point focal', true);
        $focal->accorder($permissions['activites.consulter']);
        $focal->accorder($permissions['activites.modifier']);
        $this->em->persist($focal);

        $structure = new Structure('PTB-A', 'Structure A', TypeStructure::DIRECTION_ANSD);
        $autre = new Structure('PTB-B', 'Structure B', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($structure);
        $this->em->persist($autre);

        $comptes = [];
        foreach (['ugp' => [$ugp, null], 'focal' => [$focal, $structure]] as $nom => [$role, $portee]) {
            $u = new Utilisateur("{$nom}.ptba@example.test", 'temp', 'Test', ucfirst($nom));
            $u->setMotDePasseHash($hasher->hashPassword($u, self::MOT_DE_PASSE));
            $u->marquerMdpChange();
            $this->em->persist($u);
            $this->em->persist(new UtilisateurRole($u, $role, $portee));
            $comptes[$nom] = $u;
        }

        $projet = new Projet('TESTPTB', 'Projet PTBA', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);
        $composante = new Composante($projet, 'P9', 'Composante PTBA');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'P9.1', 'Sous-composante PTBA');
        $this->em->persist($sousComposante);
        $budgetee = new Activite($sousComposante, 'P9.1.1', 'Activité dotée', $structure);
        $vide = new Activite($sousComposante, 'P9.1.2', 'Activité sans budget', $structure);
        $this->em->persist($budgetee);
        $this->em->persist($vide);
        $this->em->persist(new Dotation($budgetee, $exercice, Montant::fromFcfa(1_000_000), $comptes['ugp']));
        $this->em->flush();

        return [$client, ['composante' => $composante, 'sousComposante' => $sousComposante, 'budgetee' => $budgetee, 'vide' => $vide, 'autre' => $autre]];
    }

    private function connecter(KernelBrowser $client, string $email): void
    {
        $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'motDePasse' => self::MOT_DE_PASSE,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
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
