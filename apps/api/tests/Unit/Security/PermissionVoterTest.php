<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

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
use App\Security\PermissionsResolver;
use App\Security\PermissionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * § 7.2 du brief : un point focal de structure ne détient la permission
 * que sur les entités de sa structure, jamais globalement. Test unitaire
 * ciblé sur le Voter — la preuve bout en bout (HTTP) est dans
 * PorteeActiviteHttpTest.
 */
final class PermissionVoterTest extends TestCase
{
    public function testUnePermissionScopeeNestAccordeeQueSurSaStructure(): void
    {
        $dsid = new Structure('DSID', 'Direction test', TypeStructure::DIRECTION_ANSD);
        $dpee = new Structure('DPEE', 'Direction test', TypeStructure::PARTENAIRE_NATIONAL);
        // Le Voter compare des id internes ; sans persistance Doctrine ces
        // entités n'en ont pas — on les assigne par réflexion, seul moyen
        // d'écrire une propriété privée gérée par Doctrine hors flush.
        $this->definirId($dsid, 1);
        $this->definirId($dpee, 2);

        $permission = new Permission('activites.modifier', 'activites', 'Modifier une activité');
        $role = new Role('point_focal_structure', 'Point focal', true);
        $role->accorder($permission);

        $utilisateur = new Utilisateur('focal@example.test', 'hash', 'Ba', 'Fatou');
        new UtilisateurRole($utilisateur, $role, $dpee);

        $activiteDsid = $this->creerActivite($dsid);
        $activiteDpee = $this->creerActivite($dpee);

        // Le sujet ici est une Activite directement construite (structureResponsable déjà en mémoire,
        // jamais un proxy) : le filtre portee_activite n'a rien à contourner, mais le Voter l'interroge
        // systématiquement — un double minimal suffit (§ resoudreStructureId de PermissionVoter).
        $filtres = $this->createMock(FilterCollection::class);
        $filtres->method('isEnabled')->willReturn(false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filtres);

        $voter = new PermissionVoter(new PermissionsResolver(), $em);
        $token = new class($utilisateur) extends NullToken {
            public function __construct(private readonly Utilisateur $utilisateur)
            {
            }

            public function getUser(): Utilisateur
            {
                return $this->utilisateur;
            }
        };

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, $activiteDpee, ['activites.modifier']),
            'Le point focal DPEE doit pouvoir modifier une activité de sa propre structure.',
        );

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $activiteDsid, ['activites.modifier']),
            'Le point focal DPEE ne doit pas pouvoir modifier une activité de la structure DSID.',
        );
    }

    private function creerActivite(Structure $structureResponsable): Activite
    {
        $projet = new Projet('TEST', 'Projet test', 'Bailleur', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2028-12-31'));
        $composante = new Composante($projet, 'CX', 'Composante test');
        $sousComposante = new SousComposante($composante, 'CX.1', 'Sous-composante test');

        return new Activite($sousComposante, 'CX.1.1', 'Activité test', $structureResponsable);
    }

    private function definirId(object $entite, int $id): void
    {
        $reflection = new \ReflectionProperty($entite, 'id');
        $reflection->setValue($entite, $id);
    }
}
