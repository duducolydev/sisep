<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810224308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute uuid à roles/permissions (§ 6.2 : /admin/roles/:uuid, jamais l\'auto-increment exposé) ; synchronise la précision DATETIME(3) déjà portée par les entités.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              dotations
            CHANGE
              cree_le cree_le DATETIME(3),
            CHANGE
              modifie_le modifie_le DATETIME(3)
        SQL);
        $this->addSql('ALTER TABLE journal_activite CHANGE cree_le cree_le DATETIME(3)');
        // Ajoutée nullable puis complétée ligne à ligne avant le NOT NULL :
        // ni un ADD COLUMN NOT NULL nu (MySQL remplirait les lignes
        // existantes avec une chaîne vide, que l'index unique refuse
        // ensuite) ni DEFAULT (UUID()) (rejeté par ce serveur — "Statement
        // is unsafe because it uses a system function that may return a
        // different value on the replica", erreur 1674) ne fonctionnent ici.
        $this->addSql('ALTER TABLE permissions ADD uuid CHAR(36) DEFAULT NULL');
        $this->backfillerUuid('permissions');
        $this->addSql('ALTER TABLE permissions MODIFY uuid CHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2DEDCC6FD17F50A6 ON permissions (uuid)');
        $this->addSql('ALTER TABLE pieces_jointes CHANGE cree_le cree_le DATETIME(3)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              projets
            CHANGE
              cree_le cree_le DATETIME(3),
            CHANGE
              modifie_le modifie_le DATETIME(3)
        SQL);
        $this->addSql('ALTER TABLE realisations CHANGE cree_le cree_le DATETIME(3)');
        $this->addSql('ALTER TABLE roles ADD uuid CHAR(36) DEFAULT NULL');
        $this->backfillerUuid('roles');
        $this->addSql('ALTER TABLE roles MODIFY uuid CHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B63E2EC7D17F50A6 ON roles (uuid)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              structures
            CHANGE
              cree_le cree_le DATETIME(3),
            CHANGE
              modifie_le modifie_le DATETIME(3)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              utilisateurs
            CHANGE
              cree_le cree_le DATETIME(3),
            CHANGE
              modifie_le modifie_le DATETIME(3)
        SQL);
    }

    /** Une valeur générée côté PHP par ligne — déterministe pour le binlog, contrairement à UUID() côté serveur. */
    private function backfillerUuid(string $table): void
    {
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM {$table}");
        foreach ($ids as $id) {
            $this->addSql("UPDATE {$table} SET uuid = ? WHERE id = ?", [Uuid::v4()->toRfc4122(), $id]);
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              dotations
            CHANGE
              cree_le cree_le DATETIME DEFAULT NULL,
            CHANGE
              modifie_le modifie_le DATETIME DEFAULT NULL
        SQL);
        $this->addSql('ALTER TABLE journal_activite CHANGE cree_le cree_le DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX UNIQ_2DEDCC6FD17F50A6 ON permissions');
        $this->addSql('ALTER TABLE permissions DROP uuid');
        $this->addSql('ALTER TABLE pieces_jointes CHANGE cree_le cree_le DATETIME DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              projets
            CHANGE
              cree_le cree_le DATETIME DEFAULT NULL,
            CHANGE
              modifie_le modifie_le DATETIME DEFAULT NULL
        SQL);
        $this->addSql('ALTER TABLE realisations CHANGE cree_le cree_le DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX UNIQ_B63E2EC7D17F50A6 ON roles');
        $this->addSql('ALTER TABLE roles DROP uuid');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              structures
            CHANGE
              cree_le cree_le DATETIME DEFAULT NULL,
            CHANGE
              modifie_le modifie_le DATETIME DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              utilisateurs
            CHANGE
              cree_le cree_le DATETIME DEFAULT NULL,
            CHANGE
              modifie_le modifie_le DATETIME DEFAULT NULL
        SQL);
    }
}
