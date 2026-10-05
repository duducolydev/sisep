<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étapes de réalisation des activités (§ 6 du CR du 22/09/2026) : gabarits
 * réutilisables, étapes instanciées sur une activité, livrables quantifiés.
 *
 * Aucune table existante n'est touchée : les sous-activités conservent leur
 * budget, seul leur rôle de suivi est repris par les étapes côté applicatif.
 */
final class Version20261001114115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute gabarits_etapes, etapes_gabarit, etapes_activite et livrables (suivi par étapes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE gabarits_etapes (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              libelle VARCHAR(160) NOT NULL,
              description LONGTEXT DEFAULT NULL,
              actif TINYINT(1) NOT NULL DEFAULT 1,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME DEFAULT NULL,
              UNIQUE INDEX UNIQ_4151AEFBD17F50A6 (uuid),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE etapes_gabarit (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              gabarit_id BIGINT UNSIGNED NOT NULL,
              libelle VARCHAR(160) NOT NULL,
              ordre SMALLINT UNSIGNED NOT NULL,
              UNIQUE INDEX UNIQ_C548EE61D17F50A6 (uuid),
              INDEX IDX_C548EE618B339010 (gabarit_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE etapes_activite (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              activite_id BIGINT UNSIGNED NOT NULL,
              libelle VARCHAR(160) NOT NULL,
              ordre SMALLINT UNSIGNED NOT NULL,
              avancement SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              valeur_actuelle NUMERIC(18, 4) DEFAULT NULL,
              unite VARCHAR(60) DEFAULT NULL,
              echeance DATE DEFAULT NULL,
              date_mise_a_jour DATE DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME DEFAULT NULL,
              UNIQUE INDEX UNIQ_EEC746C4D17F50A6 (uuid),
              INDEX IDX_EEC746C49B0F88B1 (activite_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE livrables (
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              etape_id BIGINT UNSIGNED NOT NULL,
              libelle VARCHAR(255) NOT NULL,
              unite VARCHAR(60) DEFAULT NULL,
              quantite_attendue NUMERIC(18, 4) NOT NULL,
              quantite_realisee NUMERIC(18, 4) NOT NULL DEFAULT 0,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME DEFAULT NULL,
              UNIQUE INDEX UNIQ_FF9E7800D17F50A6 (uuid),
              INDEX IDX_FF9E78004A8CA2AD (etape_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);

        $this->addSql('ALTER TABLE etapes_gabarit ADD CONSTRAINT FK_C548EE618B339010 FOREIGN KEY (gabarit_id) REFERENCES gabarits_etapes (id)');
        $this->addSql('ALTER TABLE etapes_activite ADD CONSTRAINT FK_EEC746C49B0F88B1 FOREIGN KEY (activite_id) REFERENCES activites (id)');
        $this->addSql('ALTER TABLE livrables ADD CONSTRAINT FK_FF9E78004A8CA2AD FOREIGN KEY (etape_id) REFERENCES etapes_activite (id)');

        // Garde-fous métier : l'avancement d'une étape est un pourcentage, et
        // une quantité de livrable ne peut pas être négative.
        $this->addSql('ALTER TABLE etapes_activite ADD CONSTRAINT ck_etape_avancement CHECK (avancement BETWEEN 0 AND 100)');
        $this->addSql('ALTER TABLE livrables ADD CONSTRAINT ck_livrable_quantites CHECK (quantite_attendue >= 0 AND quantite_realisee >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE livrables DROP FOREIGN KEY FK_FF9E78004A8CA2AD');
        $this->addSql('ALTER TABLE etapes_activite DROP FOREIGN KEY FK_EEC746C49B0F88B1');
        $this->addSql('ALTER TABLE etapes_gabarit DROP FOREIGN KEY FK_C548EE618B339010');
        $this->addSql('DROP TABLE livrables');
        $this->addSql('DROP TABLE etapes_activite');
        $this->addSql('DROP TABLE etapes_gabarit');
        $this->addSql('DROP TABLE gabarits_etapes');
    }
}
