<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
 * points_focaux.gerer (§ 7.2) est détenue à la fois globalement et scopée
 * par structure (point_focal_structure) — contrairement à structures.gerer,
 * jamais scopée dans le référentiel de démonstration. Ce test couvre les
 * deux : un point focal DSID gère les points focaux de sa structure, pas
 * ceux d'une autre, et ne peut jamais modifier la fiche structure elle-même.
 */
final class PorteeStructureEtPointFocalHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testUnPointFocalGereLesPointsFocauxDeSaStructurePasDUneAutre(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('POST', '/api/v1/points-focaux', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'structure' => $fixture['dsid']->getUuid()->toRfc4122(),
                'nomComplet' => 'Nouveau focal DSID',
                'fonction' => 'Adjoint',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201, 'Un point focal DSID doit pouvoir gérer les points focaux de sa propre structure.');

            $client->request('POST', '/api/v1/points-focaux', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'structure' => $fixture['dpee']->getUuid()->toRfc4122(),
                'nomComplet' => 'Intrus',
                'fonction' => 'Adjoint',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403, 'Un point focal DSID ne doit jamais gérer les points focaux de la DPEE.');
        } finally {
            $this->rollback();
        }
    }

    public function testUnPointFocalNePeutPasModifierLaFicheStructure(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('PATCH', \sprintf('/api/v1/structures/%s', $fixture['dsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'telephone' => '+221 00 00 00 00',
            ], \JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(403, 'structures.gerer n\'est jamais accordée au point focal — seule points_focaux.gerer l\'est, scopée.');

            $this->connecter($client, $fixture['coordonnateur']->getEmail());
            $client->request('PATCH', \sprintf('/api/v1/structures/%s', $fixture['dsid']->getUuid()->toRfc4122()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'telephone' => '+221 33 869 21 41',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful('Le coordonnateur détient structures.gerer en portée globale.');
        } finally {
            $this->rollback();
        }
    }

    /**
     * L'email est obligatoire à la création — destinataire du système
     * d'alertes par courriel — et ne peut pas être effacé à la modification.
     */
    public function testLaCreationEtLaModificationExigentUnEmailValide(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['coordonnateur']->getEmail());

            $client->request('POST', '/api/v1/structures', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'sigle' => 'TST-EMAIL',
                'denomination' => 'Structure sans email',
                'type' => 'direction_ansd',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'La création sans email doit être refusée.');

            $client->request('POST', '/api/v1/structures', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'sigle' => 'TST-EMAIL',
                'denomination' => 'Structure avec email',
                'type' => 'direction_ansd',
                'email' => 'pas-un-email',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, 'Un email mal formé doit être refusé.');

            $client->request('POST', '/api/v1/structures', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'sigle' => 'TST-EMAIL',
                'denomination' => 'Structure avec email',
                'type' => 'direction_ansd',
                'email' => 'contact@test-email.sn',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201);

            $structureUuid = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['uuid'];

            $client->request('PATCH', "/api/v1/structures/{$structureUuid}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => '',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, "L'email ne doit pas pouvoir être effacé une fois la structure créée.");
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

        $permPointsFocaux = new Permission('points_focaux.gerer', 'points_focaux', 'Gérer les points focaux');
        $permStructures = new Permission('structures.gerer', 'structures', 'Gérer les structures');
        $permConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $this->em->persist($permPointsFocaux);
        $this->em->persist($permStructures);
        $this->em->persist($permConsulter);

        $rolePointFocal = new Role('point_focal_structure_test', 'Point focal de structure', true);
        $rolePointFocal->accorder($permPointsFocaux);
        $rolePointFocal->accorder($permConsulter);
        $this->em->persist($rolePointFocal);

        $roleCoordonnateur = new Role('coordonnateur_structure_test', 'Coordonnateur', true);
        $roleCoordonnateur->accorder($permStructures);
        $roleCoordonnateur->accorder($permConsulter);
        $this->em->persist($roleCoordonnateur);

        $dsid = new Structure('DSID-T', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $dpee = new Structure('DPEE-T', 'Direction de la prévision', TypeStructure::PARTENAIRE_NATIONAL);
        $this->em->persist($dsid);
        $this->em->persist($dpee);

        $focalDsid = new Utilisateur('focal.dsid.test@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focalDsid->setMotDePasseHash($hasher->hashPassword($focalDsid, self::MOT_DE_PASSE));
        $focalDsid->marquerMdpChange();
        $this->em->persist($focalDsid);
        $this->em->persist(new UtilisateurRole($focalDsid, $rolePointFocal, $dsid));

        $coordonnateur = new Utilisateur('coordonnateur.structure.test@example.test', 'temp', 'Diagne', 'Aïssatou');
        $coordonnateur->setMotDePasseHash($hasher->hashPassword($coordonnateur, self::MOT_DE_PASSE));
        $coordonnateur->marquerMdpChange();
        $this->em->persist($coordonnateur);
        $this->em->persist(new UtilisateurRole($coordonnateur, $roleCoordonnateur, null));

        $this->em->flush();

        return [$client, [
            'focalDsid' => $focalDsid,
            'coordonnateur' => $coordonnateur,
            'dsid' => $dsid,
            'dpee' => $dpee,
        ]];
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
}
