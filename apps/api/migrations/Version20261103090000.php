<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Décision du 03/11/2026 : les valeurs d'indicateurs saisies par le
 * responsable suivi-évaluation sont validées dès la saisie, sans second
 * contrôle par le coordonnateur — comme ses engagements et dépenses depuis
 * le 28/10/2026. Nouvelle permission `indicateurs.validation_directe`,
 * accordée au seul rôle RSE ; la retirer au rôle rétablit le contrôle par
 * une autre personne.
 *
 * Même catalogue que ReferentielApplicatif, appliqué à une base déjà en
 * service ; sans effet sur une base neuve. Rejouable sans effet.
 */
final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Valeurs d'indicateurs du RSE validées dès la saisie : permission indicateurs.validation_directe.";
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
            [Uuid::v4()->toRfc4122(), 'indicateurs.validation_directe', 'indicateurs', "Valeurs d'indicateurs validées dès leur saisie, sans second contrôle", 'indicateurs.validation_directe'],
        );
        $this->addSql(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'indicateurs.validation_directe'
             WHERE r.code = 'responsable_suivi_evaluation'
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code = 'indicateurs.validation_directe'",
        );
        $this->addSql("DELETE FROM permissions WHERE code = 'indicateurs.validation_directe'");
    }
}
