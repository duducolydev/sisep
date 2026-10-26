<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le responsable suivi-évaluation valide les valeurs d'indicateurs saisies
 * par les sectoriels (décision du 26/10/2026). Le catalogue des rôles
 * (ReferentielApplicatif) n'est semé qu'à l'installation : une base déjà en
 * service garde l'ancien rôle tant que cette migration ne lui ajoute pas la
 * permission.
 *
 * Sans effet si le rôle ou la permission manque (base vide, amorcée ensuite
 * avec le catalogue à jour) ou si l'attribution existe déjà.
 */
final class Version20261026100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rôle responsable suivi-évaluation : ajout de la permission indicateurs.valider.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'indicateurs.valider'
             WHERE r.code = 'responsable_suivi_evaluation'
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE rp FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
             WHERE r.code = 'responsable_suivi_evaluation' AND p.code = 'indicateurs.valider'",
        );
    }
}
