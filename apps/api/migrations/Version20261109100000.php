<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Décision du 09/11/2026 : le montant notifié d'une dotation peut être révisé,
 * avec un motif, par le responsable suivi-évaluation et le coordonnateur.
 * Nouvelle permission `budget.dotation.reviser` ; ni le gestionnaire
 * budgétaire ni l'administrateur ne la détiennent.
 *
 * Même catalogue que ReferentielApplicatif, appliqué à une base déjà en
 * service ; sans effet sur une base neuve. Rejouable sans effet.
 */
final class Version20261109100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Révision des dotations : permission budget.dotation.reviser pour le RSE et le coordonnateur.';
    }

    public function up(Schema $schema): void
    {
        // UUID généré ici : UUID() de MySQL est refusée par ce serveur dans
        // une écriture (erreur 1674, cf. Version20260810224308).
        $this->addSql(
            'INSERT INTO permissions (uuid, code, groupe, libelle)
             SELECT ?, ?, ?, ? FROM DUAL
             WHERE EXISTS (SELECT 1 FROM roles WHERE code = \'responsable_suivi_evaluation\')
               AND NOT EXISTS (SELECT 1 FROM permissions WHERE code = ?)',
            [Uuid::v4()->toRfc4122(), 'budget.dotation.reviser', 'budget', "Réviser le montant notifié d'une dotation", 'budget.dotation.reviser'],
        );
        $this->addSql(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'budget.dotation.reviser'
             WHERE r.code IN ('responsable_suivi_evaluation', 'coordonnateur')
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code = 'budget.dotation.reviser'",
        );
        $this->addSql("DELETE FROM permissions WHERE code = 'budget.dotation.reviser'");
    }
}
