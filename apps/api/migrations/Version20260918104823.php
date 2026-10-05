<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ajoute invitations_indicateurs (§ décision : invitation d'un sectoriel à saisir un indicateur précis). */
final class Version20260918104823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute la table invitations_indicateurs (invitation d'un sectoriel à saisir un indicateur précis).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE invitations_indicateurs (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              indicateur_id BIGINT UNSIGNED NOT NULL,
              utilisateur_id BIGINT UNSIGNED NOT NULL,
              invite_par_id BIGINT UNSIGNED NOT NULL,
              invite_le DATETIME(3) NOT NULL,
              UNIQUE INDEX UNIQ_C909ABA6D17F50A6 (uuid),
              INDEX IDX_C909ABA6DA3B8F3D (indicateur_id),
              INDEX IDX_C909ABA6FB88E14F (utilisateur_id),
              INDEX IDX_C909ABA66B8B1C79 (invite_par_id),
              UNIQUE INDEX uniq_invitation_indicateur_utilisateur (indicateur_id, utilisateur_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE invitations_indicateurs ADD CONSTRAINT FK_C909ABA6DA3B8F3D FOREIGN KEY (indicateur_id) REFERENCES indicateurs (id)');
        $this->addSql('ALTER TABLE invitations_indicateurs ADD CONSTRAINT FK_C909ABA6FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE invitations_indicateurs ADD CONSTRAINT FK_C909ABA66B8B1C79 FOREIGN KEY (invite_par_id) REFERENCES utilisateurs (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitations_indicateurs DROP FOREIGN KEY FK_C909ABA6DA3B8F3D');
        $this->addSql('ALTER TABLE invitations_indicateurs DROP FOREIGN KEY FK_C909ABA6FB88E14F');
        $this->addSql('ALTER TABLE invitations_indicateurs DROP FOREIGN KEY FK_C909ABA66B8B1C79');
        $this->addSql('DROP TABLE invitations_indicateurs');
    }
}
