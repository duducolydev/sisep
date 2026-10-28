<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Export des graphiques en image (décision du 28/10/2026) : nouvelle
 * permission `graphiques.exporter`, accordée à l'administrateur, au
 * coordonnateur et au responsable suivi-évaluation — ceux qui rédigent les
 * rapports.
 *
 * Même catalogue que ReferentielApplicatif, appliqué à une base déjà en
 * service ; sans effet sur une base neuve. Rejouable sans effet.
 */
final class Version20261028100000 extends AbstractMigration
{
    private const ROLES = ['administrateur', 'coordonnateur', 'responsable_suivi_evaluation'];

    public function getDescription(): string
    {
        return 'Export des graphiques en image : permission graphiques.exporter.';
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
            [Uuid::v4()->toRfc4122(), 'graphiques.exporter', 'exports', 'Exporter les graphiques en image', 'graphiques.exporter'],
        );
        $this->addSql(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = \'graphiques.exporter\'
             WHERE r.code IN (?, ?, ?)
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)',
            self::ROLES,
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code = 'graphiques.exporter'",
        );
        $this->addSql("DELETE FROM permissions WHERE code = 'graphiques.exporter'");
    }
}
