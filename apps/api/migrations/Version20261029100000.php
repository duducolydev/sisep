<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Récupération de mot de passe par courriel (« Mot de passe oublié »,
 * 29/10/2026) : liens en attente, dont seule l'empreinte SHA-256 est gardée.
 * Ajout pur, sans toucher aux données existantes.
 */
final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mot de passe oublié : table jetons_reinitialisation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE jetons_reinitialisation (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              utilisateur_id BIGINT UNSIGNED NOT NULL,
              empreinte CHAR(64) NOT NULL,
              cree_le DATETIME(3) NOT NULL,
              expire_le DATETIME(3) NOT NULL,
              UNIQUE INDEX UNIQ_916C745A32AF9675 (empreinte),
              UNIQUE INDEX UNIQ_916C745AD17F50A6 (uuid),
              INDEX IDX_916C745AFB88E14F (utilisateur_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE jetons_reinitialisation ADD CONSTRAINT FK_916C745AFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE jetons_reinitialisation');
    }
}
