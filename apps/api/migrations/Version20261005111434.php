<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suivi des ressources humaines (§ 10 et § 11 du CR du 22/09/2026) :
 * personnel technique nominatif, contrats, et effectifs d'enquêteurs
 * agrégés par opération.
 *
 * Aucune colonne salariale : la réunion a écarté ces informations comme
 * sensibles (§ 14).
 */
final class Version20261005111434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute personnels, contrats et effectifs_enqueteurs (suivi RH, sans données salariales).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE personnels (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              nom VARCHAR(120) NOT NULL,
              prenom VARCHAR(120) NOT NULL,
              sexe VARCHAR(1) NOT NULL,
              fonction VARCHAR(160) NOT NULL,
              profil VARCHAR(80) DEFAULT NULL,
              specialite VARCHAR(120) DEFAULT NULL,
              email VARCHAR(160) DEFAULT NULL,
              telephone VARCHAR(40) DEFAULT NULL,
              structure_id BIGINT UNSIGNED DEFAULT NULL,
              activite_id BIGINT UNSIGNED DEFAULT NULL,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME DEFAULT NULL,
              UNIQUE INDEX UNIQ_7AC38C2BD17F50A6 (uuid),
              INDEX IDX_7AC38C2B2534008B (structure_id),
              INDEX IDX_7AC38C2B9B0F88B1 (activite_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE contrats (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              personnel_id BIGINT UNSIGNED NOT NULL,
              date_debut DATE NOT NULL,
              date_fin DATE DEFAULT NULL,
              type VARCHAR(60) DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME DEFAULT NULL,
              UNIQUE INDEX UNIQ_7268396CD17F50A6 (uuid),
              INDEX IDX_7268396C1C109075 (personnel_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE effectifs_enqueteurs (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              activite_id BIGINT UNSIGNED NOT NULL,
              libelle VARCHAR(160) DEFAULT NULL,
              annee SMALLINT UNSIGNED NOT NULL,
              nombre_femmes INT UNSIGNED NOT NULL DEFAULT 0,
              nombre_hommes INT UNSIGNED NOT NULL DEFAULT 0,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME DEFAULT NULL,
              UNIQUE INDEX UNIQ_9838D95FD17F50A6 (uuid),
              INDEX IDX_9838D95F9B0F88B1 (activite_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);

        $this->addSql('ALTER TABLE personnels ADD CONSTRAINT FK_7AC38C2B2534008B FOREIGN KEY (structure_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE personnels ADD CONSTRAINT FK_7AC38C2B9B0F88B1 FOREIGN KEY (activite_id) REFERENCES activites (id)');
        $this->addSql('ALTER TABLE contrats ADD CONSTRAINT FK_7268396C1C109075 FOREIGN KEY (personnel_id) REFERENCES personnels (id)');
        $this->addSql('ALTER TABLE effectifs_enqueteurs ADD CONSTRAINT FK_9838D95F9B0F88B1 FOREIGN KEY (activite_id) REFERENCES activites (id)');

        // VARCHAR + enum PHP + CHECK posée à la main, jamais d'ENUM MySQL natif
        // (décision actée, § 4 du brief).
        $this->addSql("ALTER TABLE personnels ADD CONSTRAINT ck_personnel_sexe CHECK (sexe IN ('M', 'F'))");
        // Une période contractuelle ne peut pas se terminer avant d'avoir commencé.
        $this->addSql('ALTER TABLE contrats ADD CONSTRAINT ck_contrat_periode CHECK (date_fin IS NULL OR date_fin >= date_debut)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE effectifs_enqueteurs DROP FOREIGN KEY FK_9838D95F9B0F88B1');
        $this->addSql('ALTER TABLE contrats DROP FOREIGN KEY FK_7268396C1C109075');
        $this->addSql('ALTER TABLE personnels DROP FOREIGN KEY FK_7AC38C2B2534008B');
        $this->addSql('ALTER TABLE personnels DROP FOREIGN KEY FK_7AC38C2B9B0F88B1');
        $this->addSql('DROP TABLE effectifs_enqueteurs');
        $this->addSql('DROP TABLE contrats');
        $this->addSql('DROP TABLE personnels');
    }
}
