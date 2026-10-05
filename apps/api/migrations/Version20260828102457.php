<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remplace le suivi des diligences par le suivi des projets (« Projets » à
 * l'écran, Initiative dans le code — cf. Initiative::class, qui évite la
 * collision avec l'entité racine Projet du PTBA). Les lignes
 * `CHANGE ... DATETIME(3)` générées par `doctrine:migrations:diff` sur des
 * tables non liées (dotations, journal_activite, pieces_jointes, projets,
 * realisations, structures, utilisateurs) ont été retirées : même dérive de
 * précision préexistante déjà écartée dans Version20260814115317, sans
 * rapport avec cette fonctionnalité.
 */
final class Version20260828102457 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace le suivi des diligences par le suivi des projets : initiatives, initiative_activites, initiative_activite_responsables, taches_initiative.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE initiative_activites (
              libelle VARCHAR(255) NOT NULL,
              ordre SMALLINT UNSIGNED NOT NULL,
              date_debut DATE DEFAULT NULL,
              echeance DATE DEFAULT NULL,
              statut VARCHAR(20) NOT NULL,
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME,
              initiative_id BIGINT UNSIGNED NOT NULL,
              UNIQUE INDEX UNIQ_783B4BC5D17F50A6 (uuid),
              INDEX IDX_783B4BC5AB7D9771 (initiative_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE initiative_activite_responsables (
              initiative_activite_id BIGINT UNSIGNED NOT NULL,
              utilisateur_id BIGINT UNSIGNED NOT NULL,
              INDEX IDX_4AFAD302D9ED1BFC (initiative_activite_id),
              INDEX IDX_4AFAD302FB88E14F (utilisateur_id),
              PRIMARY KEY (
                initiative_activite_id, utilisateur_id
              )
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE initiatives (
              libelle VARCHAR(160) NOT NULL,
              description LONGTEXT DEFAULT NULL,
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME,
              composante_id BIGINT UNSIGNED DEFAULT NULL,
              sous_composante_id BIGINT UNSIGNED DEFAULT NULL,
              cree_par_id BIGINT UNSIGNED NOT NULL,
              UNIQUE INDEX UNIQ_41EA05CED17F50A6 (uuid),
              INDEX IDX_41EA05CEAC12F1AD (composante_id),
              INDEX IDX_41EA05CE9175F422 (sous_composante_id),
              INDEX IDX_41EA05CEFC29C013 (cree_par_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE taches_initiative (
              libelle VARCHAR(255) NOT NULL,
              ordre SMALLINT UNSIGNED NOT NULL,
              statut VARCHAR(20) NOT NULL,
              date_realisation DATETIME,
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) NOT NULL,
              cree_le DATETIME(3),
              modifie_le DATETIME(3),
              supprime_le DATETIME,
              initiative_activite_id BIGINT UNSIGNED NOT NULL,
              responsable_id BIGINT UNSIGNED DEFAULT NULL,
              UNIQUE INDEX UNIQ_C6C1FB1D17F50A6 (uuid),
              INDEX IDX_C6C1FB1D9ED1BFC (initiative_activite_id),
              INDEX IDX_C6C1FB153C59D72 (responsable_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              initiative_activites
            ADD
              CONSTRAINT FK_783B4BC5AB7D9771 FOREIGN KEY (initiative_id) REFERENCES initiatives (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              initiative_activite_responsables
            ADD
              CONSTRAINT FK_4AFAD302D9ED1BFC FOREIGN KEY (initiative_activite_id) REFERENCES initiative_activites (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              initiative_activite_responsables
            ADD
              CONSTRAINT FK_4AFAD302FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              initiatives
            ADD
              CONSTRAINT FK_41EA05CEAC12F1AD FOREIGN KEY (composante_id) REFERENCES composantes (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              initiatives
            ADD
              CONSTRAINT FK_41EA05CE9175F422 FOREIGN KEY (sous_composante_id) REFERENCES sous_composantes (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              initiatives
            ADD
              CONSTRAINT FK_41EA05CEFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              taches_initiative
            ADD
              CONSTRAINT FK_C6C1FB1D9ED1BFC FOREIGN KEY (initiative_activite_id) REFERENCES initiative_activites (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              taches_initiative
            ADD
              CONSTRAINT FK_C6C1FB153C59D72 FOREIGN KEY (responsable_id) REFERENCES utilisateurs (id)
        SQL);
        $this->addSql('ALTER TABLE diligences DROP FOREIGN KEY `FK_56E1CAB389D40298`');
        $this->addSql('ALTER TABLE notes_diligence DROP FOREIGN KEY `FK_FCD9755D3A3758E0`');
        $this->addSql('ALTER TABLE notes_diligence DROP FOREIGN KEY `FK_FCD9755DFC29C013`');
        $this->addSql('ALTER TABLE taches_diligence DROP FOREIGN KEY `FK_9C268FE03A3758E0`');
        $this->addSql('ALTER TABLE taches_diligence DROP FOREIGN KEY `FK_9C268FE053C59D72`');
        $this->addSql('DROP TABLE diligences');
        $this->addSql('DROP TABLE notes_diligence');
        $this->addSql('DROP TABLE taches_diligence');

        // VARCHAR + enum PHP natif + CHECK ajouté à la main (pas d'ENUM MySQL natif, § 4 du brief).
        $this->addSql("ALTER TABLE initiative_activites ADD CONSTRAINT chk_initiative_activite_statut CHECK (statut IN ('planifiee', 'en_cours', 'terminee', 'suspendue', 'annulee'))");
        $this->addSql("ALTER TABLE taches_initiative ADD CONSTRAINT chk_tache_initiative_statut CHECK (statut IN ('a_faire', 'en_cours', 'faite'))");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE diligences (
              libelle VARCHAR(160) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              description LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`,
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              cree_le DATETIME DEFAULT NULL,
              modifie_le DATETIME DEFAULT NULL,
              supprime_le DATETIME DEFAULT NULL,
              exercice_id BIGINT UNSIGNED NOT NULL,
              INDEX IDX_56E1CAB389D40298 (exercice_id),
              UNIQUE INDEX UNIQ_56E1CAB3D17F50A6 (uuid),
              UNIQUE INDEX uniq_diligence_libelle_exercice (
                libelle, exercice_id, supprime_le
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE notes_diligence (
              type VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              contenu LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              cree_le DATETIME DEFAULT NULL,
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              diligence_id BIGINT UNSIGNED NOT NULL,
              cree_par_id BIGINT UNSIGNED DEFAULT NULL,
              INDEX IDX_FCD9755D3A3758E0 (diligence_id),
              INDEX IDX_FCD9755DFC29C013 (cree_par_id),
              UNIQUE INDEX UNIQ_FCD9755DD17F50A6 (uuid),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE taches_diligence (
              libelle VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              ordre SMALLINT UNSIGNED NOT NULL,
              menee TINYINT NOT NULL,
              date_menee DATETIME DEFAULT NULL,
              id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
              uuid CHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`,
              cree_le DATETIME DEFAULT NULL,
              modifie_le DATETIME DEFAULT NULL,
              supprime_le DATETIME DEFAULT NULL,
              diligence_id BIGINT UNSIGNED NOT NULL,
              responsable_id BIGINT UNSIGNED DEFAULT NULL,
              INDEX IDX_9C268FE03A3758E0 (diligence_id),
              INDEX IDX_9C268FE053C59D72 (responsable_id),
              UNIQUE INDEX UNIQ_9C268FE0D17F50A6 (uuid),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              diligences
            ADD
              CONSTRAINT `FK_56E1CAB389D40298` FOREIGN KEY (exercice_id) REFERENCES exercices (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              notes_diligence
            ADD
              CONSTRAINT `FK_FCD9755D3A3758E0` FOREIGN KEY (diligence_id) REFERENCES diligences (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              notes_diligence
            ADD
              CONSTRAINT `FK_FCD9755DFC29C013` FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              taches_diligence
            ADD
              CONSTRAINT `FK_9C268FE03A3758E0` FOREIGN KEY (diligence_id) REFERENCES diligences (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              taches_diligence
            ADD
              CONSTRAINT `FK_9C268FE053C59D72` FOREIGN KEY (responsable_id) REFERENCES utilisateurs (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql('ALTER TABLE initiative_activites DROP CONSTRAINT chk_initiative_activite_statut');
        $this->addSql('ALTER TABLE taches_initiative DROP CONSTRAINT chk_tache_initiative_statut');
        $this->addSql('ALTER TABLE initiative_activites DROP FOREIGN KEY FK_783B4BC5AB7D9771');
        $this->addSql('ALTER TABLE initiative_activite_responsables DROP FOREIGN KEY FK_4AFAD302D9ED1BFC');
        $this->addSql('ALTER TABLE initiative_activite_responsables DROP FOREIGN KEY FK_4AFAD302FB88E14F');
        $this->addSql('ALTER TABLE initiatives DROP FOREIGN KEY FK_41EA05CEAC12F1AD');
        $this->addSql('ALTER TABLE initiatives DROP FOREIGN KEY FK_41EA05CE9175F422');
        $this->addSql('ALTER TABLE initiatives DROP FOREIGN KEY FK_41EA05CEFC29C013');
        $this->addSql('ALTER TABLE taches_initiative DROP FOREIGN KEY FK_C6C1FB1D9ED1BFC');
        $this->addSql('ALTER TABLE taches_initiative DROP FOREIGN KEY FK_C6C1FB153C59D72');
        $this->addSql('DROP TABLE initiative_activites');
        $this->addSql('DROP TABLE initiative_activite_responsables');
        $this->addSql('DROP TABLE initiatives');
        $this->addSql('DROP TABLE taches_initiative');
    }
}
