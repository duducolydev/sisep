<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Amorcage\ReferentielApplicatif;
use App\Entity\Indicateur;
use App\Entity\IndicateurValeur;
use App\Entity\Projet;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\FrequenceIndicateur;
use App\Enum\SensIndicateur;
use App\Enum\TypeIndicateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Décision du 03/11/2026 : le RSE n'a pas besoin d'être validé, pour les
 * indicateurs comme pour le budget (28/10/2026). Ses saisies sont validées
 * d'emblée, à son nom ; les autres profils ne changent pas. Bâti sur le
 * référentiel réel des droits, pour vérifier la permission telle qu'elle
 * est accordée en production.
 */
final class IndicateurValidationDirecteHttpTest extends WebTestCase
{
    private const MOT_DE_PASSE = 'MotDePasseValide123!';

    private EntityManagerInterface $em;

    public function testLeRseNAPasBesoinDEtreValide(): void
    {
        [$client, $f] = $this->demarrer();

        try {
            $indicateur = $f['indicateur'];
            $saisir = function (string $periode, string $valeur) use ($client, $indicateur): array {
                $client->request('POST', "/api/v1/indicateurs/{$indicateur}/valeurs", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                    'periode' => $periode,
                    'valeur' => $valeur,
                    'desagregations' => [],
                ], \JSON_THROW_ON_ERROR));
                self::assertResponseIsSuccessful();

                return $this->corps($client);
            };

            $this->connecter($client, 'rse@example.test');
            $saisie = $saisir('2026-06-30', '40');
            self::assertSame('valide', $saisie['statut'], 'Validée dès la saisie.');
            self::assertSame('Test rse', $saisie['validePar']);

            $client->request('GET', "/api/v1/indicateurs/{$indicateur}");
            $fiche = $this->corps($client);
            self::assertEqualsWithDelta(40.0, (float) $fiche['actuel'], 0.0001, 'Valeur officielle sans attendre le coordonnateur.');
            self::assertNull($fiche['enAttente']);

            // Sa resaisie de la même période reste validée.
            $resaisie = $saisir('2026-06-30', '45');
            self::assertSame($saisie['uuid'], $resaisie['uuid']);
            self::assertSame('valide', $resaisie['statut']);

            // Une saisie restée en attente d'avant la décision : il la solde lui-même.
            $client->request('POST', "/api/v1/indicateurs/valeurs/{$f['brouillonRse']}/valider");
            self::assertResponseIsSuccessful();
            self::assertSame('valide', $this->corps($client)['statut']);

            // L'administrateur saisit en brouillon et ne valide toujours pas sa propre saisie.
            $this->connecter($client, 'admin@example.test');
            $admin = $saisir('2027-06-30', '50');
            self::assertSame('brouillon', $admin['statut']);
            $client->request('POST', "/api/v1/indicateurs/valeurs/{$admin['uuid']}/valider");
            self::assertResponseStatusCodeSame(422);

            // Le coordonnateur valide toujours ce que saisissent les autres.
            $this->connecter($client, 'coord@example.test');
            $client->request('POST', "/api/v1/indicateurs/valeurs/{$admin['uuid']}/valider");
            self::assertResponseIsSuccessful();
            self::assertSame('valide', $this->corps($client)['statut']);
        } finally {
            $this->rollback();
        }
    }

    /** @return array{0: KernelBrowser, 1: array{indicateur: string, brouillonRse: string}} */
    private function demarrer(): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $referentiel = $container->get(ReferentielApplicatif::class);
        $roles = $referentiel->semerRoles($referentiel->semerPermissions());

        $comptes = [];
        foreach (['rse' => 'responsable_suivi_evaluation', 'coord' => 'coordonnateur', 'admin' => 'administrateur'] as $nom => $codeRole) {
            $u = new Utilisateur($nom.'@example.test', 'temp', $nom, 'Test');
            $u->setMotDePasseHash($hasher->hashPassword($u, self::MOT_DE_PASSE));
            $u->marquerMdpChange();
            $this->em->persist($u);
            $this->em->persist(new UtilisateurRole($u, $roles[$codeRole], null));
            $comptes[$nom] = $u;
        }

        $projet = new Projet('TESTVD', 'Projet validation directe', 'Banque mondiale', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $this->em->persist($projet);
        $indicateur = new Indicateur($projet, 'IND-VD', 'Indicateur validation directe', TypeIndicateur::IRI, 'Nombre', SensIndicateur::CROISSANT, '0', new \DateTimeImmutable('2025-01-01'), '100', new \DateTimeImmutable('2028-12-31'), FrequenceIndicateur::ANNUELLE, 'Source');
        $this->em->persist($indicateur);
        $brouillon = new IndicateurValeur($indicateur, new \DateTimeImmutable('2025-06-30'), '10', $comptes['rse']);
        $this->em->persist($brouillon);

        $this->em->flush();

        return [$client, ['indicateur' => $indicateur->getUuid()->toRfc4122(), 'brouillonRse' => $brouillon->getUuid()->toRfc4122()]];
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
