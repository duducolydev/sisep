<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Décision du 09/11/2026 : une écriture validée erronée se corrige en l'annulant
 * avec un motif, puis en la ressaisissant. Colonne `motif_annulation` sur les
 * engagements et les dépenses ; nouvelle permission `budget.ecriture.corriger`
 * pour le responsable suivi-évaluation et le coordonnateur, ni le gestionnaire
 * budgétaire ni l'administrateur ne la détiennent.
 *
 * Même catalogue que ReferentielApplicatif, appliqué à une base déjà en
 * service. La permission est sans effet sur une base neuve, rejouable sans effet.
 */
final class Version20261109110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Annulation motivée des écritures validées : motif_annulation et permission budget.ecriture.corriger.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE engagements ADD motif_annulation LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE depenses ADD motif_annulation LONGTEXT DEFAULT NULL');

        // UUID généré ici : UUID() de MySQL est refusée par ce serveur dans
        // une écriture (erreur 1674, cf. Version20260810224308).
        $this->addSql(
            'INSERT INTO permissions (uuid, code, groupe, libelle)
             SELECT ?, ?, ?, ? FROM DUAL
             WHERE EXISTS (SELECT 1 FROM roles WHERE code = \'responsable_suivi_evaluation\')
               AND NOT EXISTS (SELECT 1 FROM permissions WHERE code = ?)',
            [Uuid::v4()->toRfc4122(), 'budget.ecriture.corriger', 'budget', 'Annuler une écriture validée, avec un motif, pour la corriger', 'budget.ecriture.corriger'],
        );
        $this->addSql(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'budget.ecriture.corriger'
             WHERE r.code IN ('responsable_suivi_evaluation', 'coordonnateur')
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code = 'budget.ecriture.corriger'",
        );
        $this->addSql("DELETE FROM permissions WHERE code = 'budget.ecriture.corriger'");
        $this->addSql('ALTER TABLE engagements DROP motif_annulation');
        $this->addSql('ALTER TABLE depenses DROP motif_annulation');
    }
}
