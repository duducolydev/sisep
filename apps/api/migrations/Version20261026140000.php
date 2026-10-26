<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Le module Projets est réservé à l'UGP (décision du 26/10/2026) : nouvelle
 * permission `initiatives.consulter`, accordée aux rôles de l'UGP —
 * administrateur, coordonnateur, responsable suivi-évaluation, gestionnaire
 * budgétaire. Le point focal et le lecteur ne l'ont pas ; l'administration
 * des rôles peut l'accorder à un autre rôle.
 *
 * Même catalogue que ReferentielApplicatif, appliqué à une base déjà en
 * service ; sans effet sur une base neuve (app:amorcer y sème le catalogue
 * à jour). Rejouable sans effet.
 */
final class Version20261026140000 extends AbstractMigration
{
    private const ROLES = ['administrateur', 'coordonnateur', 'responsable_suivi_evaluation', 'gestionnaire_budgetaire'];

    public function getDescription(): string
    {
        return 'Module Projets réservé à l\'UGP : permission initiatives.consulter.';
    }

    public function up(Schema $schema): void
    {
        // UUID généré ici : UUID() de MySQL est refusée par ce serveur dans
        // une écriture (erreur 1674, cf. Version20260810224308).
        $this->addSql(
            'INSERT INTO permissions (uuid, code, groupe, libelle)
             SELECT ?, ?, ?, ? FROM DUAL
             WHERE EXISTS (SELECT 1 FROM roles WHERE code = \'coordonnateur\')
               AND NOT EXISTS (SELECT 1 FROM permissions WHERE code = ?)',
            [Uuid::v4()->toRfc4122(), 'initiatives.consulter', 'initiatives', "Consulter le suivi des projets (module réservé à l'UGP)", 'initiatives.consulter'],
        );
        $this->addSql(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = \'initiatives.consulter\'
             WHERE r.code IN (?, ?, ?, ?)
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)',
            self::ROLES,
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code = 'initiatives.consulter'",
        );
        $this->addSql("DELETE FROM permissions WHERE code = 'initiatives.consulter'");
    }
}
