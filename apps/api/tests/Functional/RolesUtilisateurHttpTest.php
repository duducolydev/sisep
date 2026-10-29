<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JournalActivite;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rôles d'un compte existant (PUT /admin/utilisateurs/{uuid}/roles) — avant,
 * un rôle ne se donnait qu'à la création du compte.
 */
final class RolesUtilisateurHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLesRolesDUnCompteSeRemplacentEtSontJournalises(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'admin.roles@example.test');
            $url = '/api/v1/admin/utilisateurs/'.$f['agent']->getUuid()->toRfc4122().'/roles';

            // Point focal de la DSID, puis RSE à la place.
            $this->envoyer($client, $url, [['role' => 'point_focal_structure', 'structure' => $f['dsid']->getUuid()->toRfc4122()]]);
            self::assertResponseIsSuccessful();
            self::assertSame([['role' => 'point_focal_structure', 'structure' => $f['dsid']->getUuid()->toRfc4122()]], $this->roles($client));

            $this->envoyer($client, $url, [['role' => 'responsable_suivi_evaluation']]);
            self::assertResponseIsSuccessful();
            self::assertSame([['role' => 'responsable_suivi_evaluation', 'structure' => null]], $this->roles($client));

            // Le retrait est journalisé avec le rôle retiré, pas seulement un identifiant.
            $suppressions = $this->em->getRepository(JournalActivite::class)->findBy(['entiteType' => 'attribution_role', 'action' => 'suppression']);
            self::assertCount(1, $suppressions);
            self::assertSame('point_focal_structure', $suppressions[0]->getAvant()['role'] ?? null);
        } finally {
            $this->rollback();
        }
    }

    public function testLaSeparationSaisieValidationTientSurLeCumulDeRoles(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'admin.roles@example.test');
            $url = '/api/v1/admin/utilisateurs/'.$f['agent']->getUuid()->toRfc4122().'/roles';

            // Gestionnaire (saisit) + coordonnateur (valide) : la même personne ferait les deux.
            $this->envoyer($client, $url, [['role' => 'gestionnaire_budgetaire'], ['role' => 'coordonnateur']]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Cumul interdit', $this->corps($client)['title']);

            // RSE + gestionnaire : admis, le RSE est l'une des deux dérogations assumées.
            $this->envoyer($client, $url, [['role' => 'responsable_suivi_evaluation'], ['role' => 'gestionnaire_budgetaire']]);
            self::assertResponseIsSuccessful();
        } finally {
            $this->rollback();
        }
    }

    public function testUnAdministrateurNeSeRetirePasSonPropreRole(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'admin.roles@example.test');
            $this->envoyer($client, '/api/v1/admin/utilisateurs/'.$f['admin']->getUuid()->toRfc4122().'/roles', [['role' => 'coordonnateur']]);
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->rollback();
        }
    }

    /**
     * Constaté le 29/10/2026 : un administrateur limité à la DSID voyait les
     * onglets d'administration, tous refusés en 403, mais gardait tous les
     * droits d'écriture sur la DSID, cumul saisie-validation du budget
     * compris. Un tel rôle ne s'attribue que sur tout le projet.
     */
    public function testUnRoleDeToutLeProjetNeSeLimitePasAUneStructure(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'admin.roles@example.test');
            $url = '/api/v1/admin/utilisateurs/'.$f['agent']->getUuid()->toRfc4122().'/roles';
            $dsid = $f['dsid']->getUuid()->toRfc4122();

            $this->envoyer($client, $url, [['role' => 'administrateur', 'structure' => $dsid]]);
            self::assertResponseStatusCodeSame(422, 'Droit d\'administration : jamais limité à une structure.');
            self::assertSame('Portée impossible', $this->corps($client)['title']);

            // Le RSE de ce jeu n'a aucun droit admin.* : le cumul saisie-validation suffit.
            $this->envoyer($client, $url, [['role' => 'responsable_suivi_evaluation', 'structure' => $dsid]]);
            self::assertResponseStatusCodeSame(422, 'Cumul saisie-validation : jamais limité à une structure.');

            $this->envoyer($client, $url, [['role' => 'gestionnaire_budgetaire', 'structure' => $dsid]]);
            self::assertResponseIsSuccessful('Saisir sans valider se limite à une structure.');

            $this->envoyer($client, $url, [['role' => 'responsable_suivi_evaluation']]);
            self::assertResponseIsSuccessful('Sur tout le projet, le RSE reste attribuable.');

            // L'écran d'attribution lit la règle au lieu de la recopier.
            $client->request('GET', '/api/v1/admin/roles');
            $exigences = array_column($this->corps($client)['donnees'], 'porteeGlobaleExigee', 'code');
            self::assertTrue($exigences['responsable_suivi_evaluation']);
            self::assertFalse($exigences['gestionnaire_budgetaire']);
        } finally {
            $this->rollback();
        }
    }

    public function testLaCreationDUnCompteAppliqueLaMemeRegle(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'admin.roles@example.test');
            $client->request('POST', '/api/v1/admin/utilisateurs', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'administrateur.dsid@example.test',
                'nom' => 'Limité',
                'prenom' => 'Administrateur',
                'roles' => [['role' => 'administrateur', 'structure' => $f['dsid']->getUuid()->toRfc4122()]],
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
            self::assertNull(
                $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'administrateur.dsid@example.test']),
                'Refusé avant toute écriture.',
            );
        } finally {
            $this->rollback();
        }
    }

    /**
     * Même règle vue depuis la matrice : un rôle attribué limité à une
     * structure ne peut pas devenir un rôle de tout le projet, sans quoi ses
     * attributions basculeraient d'un coup dans le cas interdit.
     */
    public function testLaMatriceNeFaitPasDUnRoleLimiteUnRoleDeToutLeProjet(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->em->persist(new UtilisateurRole($f['agent'], $f['roles']['point_focal_structure'], $f['dsid']));
            $this->em->flush();
            $this->connecter($client, 'admin.roles@example.test');
            $url = '/api/v1/admin/roles/'.$f['roles']['point_focal_structure']->getUuid()->toRfc4122().'/permissions';

            $this->envoyerPermissions($client, $url, ['activites.consulter', 'admin.utilisateurs']);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('1 attribution', $this->corps($client)['detail']);

            $this->envoyerPermissions($client, $url, ['activites.consulter', 'budget.engagement.saisir']);
            self::assertResponseIsSuccessful('Saisir sans valider reste compatible avec une portée limitée.');
        } finally {
            $this->rollback();
        }
    }

    /** @param list<string> $codes */
    private function envoyerPermissions(KernelBrowser $client, string $url, array $codes): void
    {
        $client->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['permissions' => $codes], \JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, string>> $roles */
    private function envoyer(KernelBrowser $client, string $url, array $roles): void
    {
        $client->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['roles' => $roles], \JSON_THROW_ON_ERROR));
    }

    /**
     * Recours de l'administrateur, à côté du lien de récupération par
     * courriel (MotDePasseOublieHttpTest) : il attribue un mot de passe
     * temporaire. L'ancien cesse de fonctionner, et le journal retient le
     * changement sans jamais l'empreinte.
     */
    public function testLAdministrateurAttribueUnMotDePasseTemporaire(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $this->connecter($client, 'admin.roles@example.test');

            $client->request('POST', '/api/v1/admin/utilisateurs/'.$f['admin']->getUuid()->toRfc4122().'/mot-de-passe-temporaire');
            self::assertResponseStatusCodeSame(422, 'Son propre mot de passe se change depuis « Mon profil ».');

            $client->request('POST', '/api/v1/admin/utilisateurs/'.$f['agent']->getUuid()->toRfc4122().'/mot-de-passe-temporaire');
            self::assertResponseIsSuccessful();
            $temporaire = $this->corps($client)['motDePasseTemporaire'];
            self::assertIsString($temporaire);
            self::assertSame(20, \strlen($temporaire));

            $modifications = array_values(array_filter(
                $this->em->getRepository(JournalActivite::class)->findBy(['entiteType' => 'utilisateur', 'action' => 'modification']),
                static fn (JournalActivite $j) => \array_key_exists('motDePasseHash', $j->getApres() ?? []),
            ));
            self::assertCount(1, $modifications);
            self::assertSame('(masqué)', $modifications[0]->getAvant()['motDePasseHash'] ?? null, "L'empreinte ne va jamais au journal.");
            self::assertSame('(masqué)', $modifications[0]->getApres()['motDePasseHash'] ?? null);

            $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'agent.roles@example.test',
                'motDePasse' => self::MOT_DE_PASSE,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(401, "L'ancien mot de passe ne fonctionne plus.");

            $client->request('POST', '/api/v1/auth/connexion', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'agent.roles@example.test',
                'motDePasse' => $temporaire,
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            $client->request('GET', '/api/v1/auth/moi');
            self::assertTrue($this->corps($client)['doitChangerMdp'] ?? null, 'Le mot de passe temporaire est à changer.');
        } finally {
            $this->rollback();
        }
    }

    /** @return list<array{role: string, structure: string|null}> */
    private function roles(KernelBrowser $client): array
    {
        return $this->corps($client)['roles'];
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
        foreach (['admin.utilisateurs', 'admin.roles', 'budget.engagement.saisir', 'budget.engagement.valider', 'activites.consulter'] as $code) {
            $permissions[$code] = new Permission($code, explode('.', $code)[0], $code);
            $this->em->persist($permissions[$code]);
        }
        $roles = [];
        foreach ([
            'administrateur' => ['admin.utilisateurs', 'admin.roles', 'budget.engagement.saisir', 'budget.engagement.valider'],
            'responsable_suivi_evaluation' => ['budget.engagement.saisir', 'budget.engagement.valider'],
            'gestionnaire_budgetaire' => ['budget.engagement.saisir'],
            'coordonnateur' => ['budget.engagement.valider'],
            'point_focal_structure' => ['activites.consulter'],
        ] as $code => $codesPermissions) {
            $roles[$code] = new Role($code, $code, true);
            foreach ($codesPermissions as $codePermission) {
                $roles[$code]->accorder($permissions[$codePermission]);
            }
            $this->em->persist($roles[$code]);
        }

        $dsid = new Structure('DSID-R', 'Direction test', TypeStructure::DIRECTION_ANSD);
        $this->em->persist($dsid);

        $admin = new Utilisateur('admin.roles@example.test', 'temp', 'Admin', 'Test');
        $admin->setMotDePasseHash($hasher->hashPassword($admin, self::MOT_DE_PASSE));
        $admin->marquerMdpChange();
        $this->em->persist($admin);
        $this->em->persist(new UtilisateurRole($admin, $roles['administrateur']));

        $agent = new Utilisateur('agent.roles@example.test', 'temp', 'Djigo', 'Amadou');
        $agent->setMotDePasseHash($hasher->hashPassword($agent, self::MOT_DE_PASSE));
        $agent->marquerMdpChange();
        $this->em->persist($agent);

        $this->em->flush();

        return [$client, ['admin' => $admin, 'agent' => $agent, 'dsid' => $dsid, 'roles' => $roles]];
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
