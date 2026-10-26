<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Le point focal de structure n'a plus accès au module Parties prenantes
 * (décision du 26/10/2026) : il ne gère plus les points focaux ni la liaison
 * des comptes, et ne voit plus les fiches. Il garde la transmission des
 * rapports périodiques de sa structure, désormais portée par une permission
 * à part.
 *
 *  - nouvelles permissions `parties_prenantes.consulter` (équipe du projet)
 *    et `rapports.transmettre` (point focal pour sa structure, coordination
 *    pour toutes) ;
 *  - `points_focaux.gerer` retirée au rôle point_focal_structure.
 *
 * Même catalogue que ReferentielApplicatif, appliqué à une base déjà en
 * service. Rejouable sans effet.
 */
final class Version20261026120000 extends AbstractMigration
{
    /** Mêmes libellés que ReferentielApplicatif::semerPermissions(). */
    private const PERMISSIONS = [
        'parties_prenantes.consulter' => ['structures', 'Consulter les parties prenantes, leurs points focaux, rapports et activités'],
        'rapports.transmettre' => ['rapports', "Transmettre le rapport périodique d'une structure"],
    ];

    /** Code de permission => rôles qui la reçoivent. */
    private const ATTRIBUTIONS = [
        'parties_prenantes.consulter' => ['administrateur', 'coordonnateur', 'responsable_suivi_evaluation', 'gestionnaire_budgetaire', 'lecteur'],
        'rapports.transmettre' => ['administrateur', 'coordonnateur', 'point_focal_structure'],
    ];

    public function getDescription(): string
    {
        return 'Parties prenantes réservées à l\'équipe du projet ; le point focal transmet seulement ses rapports.';
    }

    public function up(Schema $schema): void
    {
        // UUID généré ici : la fonction UUID() de MySQL est refusée par ce
        // serveur dans une écriture (erreur 1674, cf. Version20260810224308).
        // Seulement si le catalogue est déjà en place : une base neuve (test,
        // nouvelle installation) le reçoit complet de app:amorcer, et des
        // permissions créées ici le feraient échouer sur un doublon.
        foreach (self::PERMISSIONS as $code => [$groupe, $libelle]) {
            $this->addSql(
                'INSERT INTO permissions (uuid, code, groupe, libelle)
                 SELECT ?, ?, ?, ? FROM DUAL
                 WHERE EXISTS (SELECT 1 FROM roles WHERE code = \'point_focal_structure\')
                   AND NOT EXISTS (SELECT 1 FROM permissions WHERE code = ?)',
                [Uuid::v4()->toRfc4122(), $code, $groupe, $libelle, $code],
            );
        }

        foreach (self::ATTRIBUTIONS as $permission => $roles) {
            $this->addSql(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = ?
                 WHERE r.code IN (?, ?, ?, ?, ?)
                   AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)',
                [$permission, ...array_pad($roles, 5, '')],
            );
        }

        $this->addSql(
            "DELETE rp FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
             WHERE r.code = 'point_focal_structure' AND p.code = 'points_focaux.gerer'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'points_focaux.gerer'
             WHERE r.code = 'point_focal_structure'
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id)",
        );
        $this->addSql(
            "DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code IN ('parties_prenantes.consulter', 'rapports.transmettre')",
        );
        $this->addSql("DELETE FROM permissions WHERE code IN ('parties_prenantes.consulter', 'rapports.transmettre')");
    }
}
