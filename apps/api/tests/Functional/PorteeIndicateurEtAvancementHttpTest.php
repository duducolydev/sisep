<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Indicateur;
use App\Entity\Permission;
use App\Entity\Projet;
use App\Entity\Role;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\FrequenceIndicateur;
use App\Enum\SensIndicateur;
use App\Enum\TypeIndicateur;
use App\Enum\TypeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Une structure doit pouvoir renseigner l'avancement de ses propres
 * activités et la valeur des indicateurs dont elle est responsable, sans
 * pouvoir le faire pour une autre structure (§ 7.2, § 4.4, § 4.6 du brief).
 */
final class PorteeIndicateurEtAvancementHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testUnPointFocalSaisitLavancementDeSesPropresSousActivites(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request(
                'POST',
                \sprintf('/api/v1/sous-activites/%s/realisations', $fixture['sousActiviteDsid']->getUuid()->toRfc4122()),
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['avancement' => 65, 'dateConstat' => '2026-06-01', 'commentaire' => 'RAS'], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(201, 'Un point focal DSID doit pouvoir saisir un constat sur sa propre sous-activité.');
            self::assertSame(65, $this->corps($client)['avancement']);

            $client->request(
                'POST',
                \sprintf('/api/v1/sous-activites/%s/realisations', $fixture['sousActiviteDpee']->getUuid()->toRfc4122()),
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['avancement' => 50, 'dateConstat' => '2026-06-01'], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(403, 'Un point focal DSID ne doit jamais saisir de constat pour la DPEE.');
        } finally {
            $this->rollback();
        }
    }

    public function testUnPointFocalSaisitLaValeurDesIndicateursDontSaStructureEstResponsable(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request(
                'POST',
                \sprintf('/api/v1/indicateurs/%s/valeurs', $fixture['indicateurDsid']->getUuid()->toRfc4122()),
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['periode' => '2026-06-01', 'valeur' => '42', 'desagregations' => []], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(201, "Un point focal doit pouvoir saisir la valeur d'un indicateur dont sa structure est responsable.");

            $client->request(
                'POST',
                \sprintf('/api/v1/indicateurs/%s/valeurs', $fixture['indicateurSansResponsable']->getUuid()->toRfc4122()),
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['periode' => '2026-06-01', 'valeur' => '1', 'desagregations' => []], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(403, "Sans structure responsable, seule une attribution globale d'indicateurs.saisir doit être acceptée.");
        } finally {
            $this->rollback();
        }
    }

    public function testLeFiltreStructureResponsableNeRenvoieQueSesIndicateurs(): void
    {
        [$client, $fixture] = $this->demarrer();

        try {
            $this->connecter($client, $fixture['focalDsid']->getEmail());

            $client->request('GET', \sprintf('/api/v1/indicateurs?structureResponsable=%s', $fixture['dsid']->getUuid()->toRfc4122()));
            self::assertResponseIsSuccessful();
            $codes = array_column($this->corps($client)['donnees'], 'code');
            self::assertSame(['IND-DSID'], $codes);
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

        $permActivitesModifier = new Permission('activites.modifier', 'activites', 'Modifier une activité');
        $permActivitesConsulter = new Permission('activites.consulter', 'activites', 'Consulter une activité');
        $permIndicateursSaisir = new Permission('indicateurs.saisir', 'indicateurs', "Saisir une valeur d'indicateur");
        $permIndicateursConsulter = new Permission('indicateurs.consulter', 'indicateurs', 'Consulter le catalogue des indicateurs de performance');
        foreach ([$permActivitesModifier, $permActivitesConsulter, $permIndicateursSaisir, $permIndicateursConsulter] as $p) {
            $this->em->persist($p);
        }

        $rolePointFocal = new Role('point_focal_structure_avancement_test', 'Point focal de structure', true);
        $rolePointFocal->accorder($permActivitesModifier);
        $rolePointFocal->accorder($permActivitesConsulter);
        $rolePointFocal->accorder($permIndicateursSaisir);
        $this->em->persist($rolePointFocal);

        // indicateurs.consulter n'existe qu'en portée globale (§ décision : le
        // module Indicateurs de performance est réservé à l'équipe PHASAOC) —
        // attribution distincte, non scopée à $dsid, uniquement pour permettre
        // à ce test de vérifier le filtre ?structureResponsable= de la liste ;
        // ne modélise pas le rôle point_focal_structure réel, qui ne détient
        // jamais cette permission.
        $roleLecture = new Role('lecture_indicateurs_avancement_test', 'Lecture indicateurs (test)', true);
        $roleLecture->accorder($permIndicateursConsulter);
        $this->em->persist($roleLecture);

        $dsid = new Structure('DSID-AV', 'Direction des systèmes d\'information', TypeStructure::DIRECTION_ANSD);
        $dpee = new Structure('DPEE-AV', 'Direction de la prévision', TypeStructure::PARTENAIRE_NATIONAL);
        $this->em->persist($dsid);
        $this->em->persist($dpee);

        $focalDsid = new Utilisateur('focal.dsid.avancement@example.test', 'temp', 'Sarr', 'Ibrahima');
        $focalDsid->setMotDePasseHash($hasher->hashPassword($focalDsid, self::MOT_DE_PASSE));
        $this->em->persist($focalDsid);
        $this->em->persist(new UtilisateurRole($focalDsid, $rolePointFocal, $dsid));
        $this->em->persist(new UtilisateurRole($focalDsid, $roleLecture, null));

        $projet = new Projet('TESTAVANCEMENT', 'Projet avancement', 'Bailleur test', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $composante = new Composante($projet, 'CAV', 'Composante avancement');
        $this->em->persist($composante);
        $sousComposante = new SousComposante($composante, 'CAV.1', 'Sous-composante avancement');
        $this->em->persist($sousComposante);

        $activiteDsid = new Activite($sousComposante, 'CAV.1.1', 'Activité DSID', $dsid);
        $this->em->persist($activiteDsid);
        $sousActiviteDsid = new SousActivite($activiteDsid, 'Sous-activité DSID');
        $this->em->persist($sousActiviteDsid);

        $activiteDpee = new Activite($sousComposante, 'CAV.1.2', 'Activité DPEE', $dpee);
        $this->em->persist($activiteDpee);
        $sousActiviteDpee = new SousActivite($activiteDpee, 'Sous-activité DPEE');
        $this->em->persist($sousActiviteDpee);

        $indicateurDsid = new Indicateur(
            $projet, 'IND-DSID', 'Indicateur DSID', TypeIndicateur::IRI, '%', SensIndicateur::CROISSANT,
            '0', new \DateTimeImmutable('2025-01-01'), '100', new \DateTimeImmutable('2028-12-31'),
            FrequenceIndicateur::ANNUELLE, 'Test',
        );
        $indicateurDsid->setStructureResponsable($dsid);
        $this->em->persist($indicateurDsid);

        $indicateurSansResponsable = new Indicateur(
            $projet, 'IND-LIBRE', 'Indicateur sans responsable', TypeIndicateur::IRI, '%', SensIndicateur::CROISSANT,
            '0', new \DateTimeImmutable('2025-01-01'), '100', new \DateTimeImmutable('2028-12-31'),
            FrequenceIndicateur::ANNUELLE, 'Test',
        );
        $this->em->persist($indicateurSansResponsable);

        $this->em->flush();

        return [$client, [
            'focalDsid' => $focalDsid,
            'dsid' => $dsid,
            'sousActiviteDsid' => $sousActiviteDsid,
            'sousActiviteDpee' => $sousActiviteDpee,
            'indicateurDsid' => $indicateurDsid,
            'indicateurSansResponsable' => $indicateurSansResponsable,
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
}
