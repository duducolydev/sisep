<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suivi des diligences (dispositifs statistiques phares — RGE, EHCVM...) :
 * entités indépendantes de l'arbre PTBA (§ décision produit). Le taux
 * d'exécution n'est pas stocké, il est calculé à l'affichage à partir des
 * tâches (DiligenceNormalizer).
 *
 * Les lignes `CHANGE ... DATETIME(3)` générées par `doctrine:migrations:diff`
 * sur des tables non liées (dotations, projets, structures...) ont été
 * retirées : c'est une dérive de précision préexistante entre le schéma réel
 * et le mapping, sans rapport avec cette fonctionnalité — pas de raison de
 * la corriger au passage dans une migration dédiée aux diligences.
 */
final class Version20260814115317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suivi des diligences : diligences, tâches et notes (réalisations / prochaines étapes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE diligences (libelle VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, cree_le DATETIME(3), modifie_le DATETIME(3), supprime_le DATETIME, exercice_id BIGINT UNSIGNED NOT NULL, UNIQUE INDEX UNIQ_56E1CAB3D17F50A6 (uuid), INDEX IDX_56E1CAB389D40298 (exercice_id), UNIQUE INDEX uniq_diligence_libelle_exercice (libelle, exercice_id, supprime_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notes_diligence (type VARCHAR(20) NOT NULL, contenu LONGTEXT NOT NULL, cree_le DATETIME(3), id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, diligence_id BIGINT UNSIGNED NOT NULL, cree_par_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_FCD9755DD17F50A6 (uuid), INDEX IDX_FCD9755D3A3758E0 (diligence_id), INDEX IDX_FCD9755DFC29C013 (cree_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE taches_diligence (libelle VARCHAR(255) NOT NULL, ordre SMALLINT UNSIGNED NOT NULL, menee TINYINT NOT NULL, date_menee DATETIME, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, cree_le DATETIME(3), modifie_le DATETIME(3), supprime_le DATETIME, diligence_id BIGINT UNSIGNED NOT NULL, responsable_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_9C268FE0D17F50A6 (uuid), INDEX IDX_9C268FE03A3758E0 (diligence_id), INDEX IDX_9C268FE053C59D72 (responsable_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE diligences ADD CONSTRAINT FK_56E1CAB389D40298 FOREIGN KEY (exercice_id) REFERENCES exercices (id)');
        $this->addSql('ALTER TABLE notes_diligence ADD CONSTRAINT FK_FCD9755D3A3758E0 FOREIGN KEY (diligence_id) REFERENCES diligences (id)');
        $this->addSql('ALTER TABLE notes_diligence ADD CONSTRAINT FK_FCD9755DFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE taches_diligence ADD CONSTRAINT FK_9C268FE03A3758E0 FOREIGN KEY (diligence_id) REFERENCES diligences (id)');
        $this->addSql('ALTER TABLE taches_diligence ADD CONSTRAINT FK_9C268FE053C59D72 FOREIGN KEY (responsable_id) REFERENCES utilisateurs (id)');

        // VARCHAR + enum PHP natif + CHECK ajouté à la main (pas d'ENUM MySQL natif, § 4 du brief).
        $this->addSql("ALTER TABLE notes_diligence ADD CONSTRAINT chk_note_diligence_type CHECK (type IN ('realisation', 'prochaine_etape'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notes_diligence DROP CONSTRAINT chk_note_diligence_type');
        $this->addSql('ALTER TABLE diligences DROP FOREIGN KEY FK_56E1CAB389D40298');
        $this->addSql('ALTER TABLE notes_diligence DROP FOREIGN KEY FK_FCD9755D3A3758E0');
        $this->addSql('ALTER TABLE notes_diligence DROP FOREIGN KEY FK_FCD9755DFC29C013');
        $this->addSql('ALTER TABLE taches_diligence DROP FOREIGN KEY FK_9C268FE03A3758E0');
        $this->addSql('ALTER TABLE taches_diligence DROP FOREIGN KEY FK_9C268FE053C59D72');
        $this->addSql('DROP TABLE diligences');
        $this->addSql('DROP TABLE notes_diligence');
        $this->addSql('DROP TABLE taches_diligence');
    }
}
