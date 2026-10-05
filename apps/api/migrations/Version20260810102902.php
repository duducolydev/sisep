<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810102902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial SISEP (§ 4 du brief) : structure du projet, budget, indicateurs, acteurs/droits, traçabilité, vues d\'agrégation.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activites (code VARCHAR(20) NOT NULL, libelle VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, date_debut DATE DEFAULT NULL, date_fin_prevue DATE DEFAULT NULL, date_fin_reelle DATE DEFAULT NULL, statut VARCHAR(20) NOT NULL, ordre SMALLINT NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, supprime_le DATETIME, sous_composante_id BIGINT UNSIGNED NOT NULL, structure_responsable_id BIGINT UNSIGNED NOT NULL, UNIQUE INDEX UNIQ_766B5EB5D17F50A6 (uuid), INDEX IDX_766B5EB59175F422 (sous_composante_id), INDEX idx_activite_structure_responsable (structure_responsable_id), INDEX idx_activite_statut (statut), UNIQUE INDEX uniq_activite_code (sous_composante_id, code, supprime_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE composantes (code VARCHAR(10) NOT NULL, libelle VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, ordre SMALLINT NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, supprime_le DATETIME, projet_id BIGINT UNSIGNED NOT NULL, structure_pilote_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_8EBC0B0DD17F50A6 (uuid), INDEX IDX_8EBC0B0DC18272 (projet_id), INDEX IDX_8EBC0B0D634807C5 (structure_pilote_id), UNIQUE INDEX uniq_composante_projet_code (projet_id, code, supprime_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE depenses (montant BIGINT UNSIGNED NOT NULL, date_depense DATE NOT NULL, reference_paiement VARCHAR(80) DEFAULT NULL, statut VARCHAR(20) NOT NULL, valide_le DATETIME, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, supprime_le DATETIME, engagement_id BIGINT UNSIGNED NOT NULL, cree_par_id BIGINT UNSIGNED NOT NULL, valide_par_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_EE350ECBD17F50A6 (uuid), INDEX IDX_EE350ECBD30F6F97 (engagement_id), INDEX IDX_EE350ECBFC29C013 (cree_par_id), INDEX IDX_EE350ECB6AF12ED9 (valide_par_id), INDEX idx_depense_engagement_statut (engagement_id, statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dotations (montant BIGINT UNSIGNED NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, cree_le DATETIME(3), modifie_le DATETIME(3), activite_id BIGINT UNSIGNED NOT NULL, exercice_id BIGINT UNSIGNED NOT NULL, cree_par_id BIGINT UNSIGNED NOT NULL, UNIQUE INDEX UNIQ_E474AE2D17F50A6 (uuid), INDEX IDX_E474AE29B0F88B1 (activite_id), INDEX IDX_E474AE289D40298 (exercice_id), INDEX IDX_E474AE2FC29C013 (cree_par_id), UNIQUE INDEX uniq_dotation_activite_exercice (activite_id, exercice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE engagements (reference_marche VARCHAR(80) DEFAULT NULL, nature VARCHAR(30) NOT NULL, montant BIGINT UNSIGNED NOT NULL, date_engagement DATE NOT NULL, statut VARCHAR(20) NOT NULL, observations LONGTEXT DEFAULT NULL, valide_le DATETIME, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, supprime_le DATETIME, activite_id BIGINT UNSIGNED NOT NULL, exercice_id BIGINT UNSIGNED NOT NULL, cree_par_id BIGINT UNSIGNED NOT NULL, valide_par_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_1A0DD10CD17F50A6 (uuid), INDEX IDX_1A0DD10C9B0F88B1 (activite_id), INDEX IDX_1A0DD10C89D40298 (exercice_id), INDEX IDX_1A0DD10CFC29C013 (cree_par_id), INDEX IDX_1A0DD10C6AF12ED9 (valide_par_id), INDEX idx_engagement_activite_exercice_statut (activite_id, exercice_id, statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE exercices (annee SMALLINT NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, statut VARCHAR(20) NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, projet_id BIGINT UNSIGNED NOT NULL, UNIQUE INDEX UNIQ_1387EAE1D17F50A6 (uuid), INDEX IDX_1387EAE1C18272 (projet_id), UNIQUE INDEX uniq_exercice_projet_annee (projet_id, annee), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE indicateur_desagregations (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, dimension VARCHAR(40) NOT NULL, modalite VARCHAR(80) NOT NULL, valeur NUMERIC(18, 4) NOT NULL, indicateur_valeur_id BIGINT UNSIGNED NOT NULL, INDEX IDX_6FCBD1B362944DBD (indicateur_valeur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE indicateur_valeurs (periode DATE NOT NULL, valeur NUMERIC(18, 4) NOT NULL, commentaire LONGTEXT DEFAULT NULL, statut VARCHAR(20) NOT NULL, valide_le DATETIME, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, indicateur_id BIGINT UNSIGNED NOT NULL, saisi_par_id BIGINT UNSIGNED NOT NULL, valide_par_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_6E1E7B03D17F50A6 (uuid), INDEX IDX_6E1E7B03DA3B8F3D (indicateur_id), INDEX IDX_6E1E7B03B0F809FE (saisi_par_id), INDEX IDX_6E1E7B036AF12ED9 (valide_par_id), UNIQUE INDEX uniq_indicateur_valeur_periode (indicateur_id, periode), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE indicateurs (code VARCHAR(20) NOT NULL, libelle VARCHAR(255) NOT NULL, precision_libelle VARCHAR(255) DEFAULT NULL, type VARCHAR(3) NOT NULL, unite VARCHAR(40) NOT NULL, sens VARCHAR(15) NOT NULL, valeur_reference NUMERIC(18, 4) NOT NULL, date_reference DATE NOT NULL, valeur_cible NUMERIC(18, 4) NOT NULL, date_cible DATE NOT NULL, frequence VARCHAR(20) NOT NULL, source VARCHAR(160) NOT NULL, actif TINYINT NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, supprime_le DATETIME, projet_id BIGINT UNSIGNED NOT NULL, composante_id BIGINT UNSIGNED DEFAULT NULL, structure_responsable_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_BE781C5AD17F50A6 (uuid), INDEX IDX_BE781C5AC18272 (projet_id), INDEX IDX_BE781C5AAC12F1AD (composante_id), INDEX IDX_BE781C5AAD41CC3B (structure_responsable_id), UNIQUE INDEX uniq_indicateur_projet_code (projet_id, code, supprime_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE journal_activite (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, action VARCHAR(60) NOT NULL, entite_type VARCHAR(40) NOT NULL, entite_id BIGINT UNSIGNED DEFAULT NULL, avant JSON DEFAULT NULL, apres JSON DEFAULT NULL, adresse_ip VARCHAR(45) NOT NULL, agent VARCHAR(255) NOT NULL, cree_le DATETIME(3), utilisateur_id BIGINT UNSIGNED DEFAULT NULL, INDEX IDX_68C5F8ACFB88E14F (utilisateur_id), INDEX idx_journal_entite (entite_type, entite_id), INDEX idx_journal_cree_le (cree_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE parametres (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, code VARCHAR(80) NOT NULL, valeur VARCHAR(40) NOT NULL, description VARCHAR(255) DEFAULT NULL, modifie_le DATETIME, modifie_par_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_1A79799D77153098 (code), INDEX IDX_1A79799D553B2554 (modifie_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE permissions (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, code VARCHAR(80) NOT NULL, groupe VARCHAR(60) NOT NULL, libelle VARCHAR(160) NOT NULL, UNIQUE INDEX UNIQ_2DEDCC6F77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pieces_jointes (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, entite_type VARCHAR(40) NOT NULL, entite_id BIGINT UNSIGNED NOT NULL, nom_fichier VARCHAR(255) NOT NULL, chemin VARCHAR(500) NOT NULL, mime VARCHAR(120) NOT NULL, taille_octets BIGINT UNSIGNED NOT NULL, cree_le DATETIME(3), cree_par_id BIGINT UNSIGNED NOT NULL, UNIQUE INDEX UNIQ_A252649BD17F50A6 (uuid), INDEX IDX_A252649BFC29C013 (cree_par_id), INDEX idx_piece_jointe_entite (entite_type, entite_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE points_focaux (nom_complet VARCHAR(160) NOT NULL, fonction VARCHAR(160) NOT NULL, telephone VARCHAR(40) DEFAULT NULL, email VARCHAR(160) DEFAULT NULL, principal TINYINT NOT NULL, date_designation DATE DEFAULT NULL, actif TINYINT NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, structure_id BIGINT UNSIGNED NOT NULL, utilisateur_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_F8234969D17F50A6 (uuid), INDEX IDX_F82349692534008B (structure_id), INDEX IDX_F8234969FB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE projets (code VARCHAR(20) NOT NULL, libelle VARCHAR(255) NOT NULL, bailleur VARCHAR(255) NOT NULL, devise VARCHAR(3) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, actif TINYINT NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, cree_le DATETIME(3), modifie_le DATETIME(3), UNIQUE INDEX UNIQ_B454C1DB77153098 (code), UNIQUE INDEX UNIQ_B454C1DBD17F50A6 (uuid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rapports_periodiques (periode VARCHAR(2) NOT NULL, statut VARCHAR(20) NOT NULL, date_echeance DATE NOT NULL, date_transmission DATETIME, commentaire LONGTEXT DEFAULT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, structure_id BIGINT UNSIGNED NOT NULL, exercice_id BIGINT UNSIGNED NOT NULL, piece_jointe_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_4A93E5D1D17F50A6 (uuid), INDEX IDX_4A93E5D12534008B (structure_id), INDEX IDX_4A93E5D189D40298 (exercice_id), INDEX IDX_4A93E5D1A3741A05 (piece_jointe_id), UNIQUE INDEX uniq_rapport_structure_exercice_periode (structure_id, exercice_id, periode), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE realisations (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, avancement SMALLINT UNSIGNED NOT NULL, date_constat DATE NOT NULL, commentaire LONGTEXT DEFAULT NULL, cree_le DATETIME(3), sous_activite_id BIGINT UNSIGNED NOT NULL, cree_par_id BIGINT UNSIGNED NOT NULL, UNIQUE INDEX UNIQ_FC5C476DD17F50A6 (uuid), INDEX IDX_FC5C476DDB36FB82 (sous_activite_id), INDEX IDX_FC5C476DFC29C013 (cree_par_id), INDEX idx_realisation_sous_activite_date (sous_activite_id, date_constat), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE roles (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, code VARCHAR(60) NOT NULL, libelle VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, systeme TINYINT NOT NULL, UNIQUE INDEX UNIQ_B63E2EC777153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE role_permissions (role_id BIGINT UNSIGNED NOT NULL, permission_id BIGINT UNSIGNED NOT NULL, INDEX IDX_1FBA94E6D60322AC (role_id), INDEX IDX_1FBA94E6FED90CCA (permission_id), PRIMARY KEY (role_id, permission_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sous_activites (libelle VARCHAR(255) NOT NULL, date_debut DATE DEFAULT NULL, echeance DATE DEFAULT NULL, statut VARCHAR(20) NOT NULL, avancement SMALLINT UNSIGNED NOT NULL, ordre SMALLINT NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, supprime_le DATETIME, activite_id BIGINT UNSIGNED NOT NULL, structure_responsable_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_6D62B5FAD17F50A6 (uuid), INDEX IDX_6D62B5FA9B0F88B1 (activite_id), INDEX IDX_6D62B5FAAD41CC3B (structure_responsable_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sous_composantes (code VARCHAR(10) NOT NULL, libelle VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, ordre SMALLINT NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, supprime_le DATETIME, composante_id BIGINT UNSIGNED NOT NULL, structure_pilote_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_CE85783ED17F50A6 (uuid), INDEX IDX_CE85783EAC12F1AD (composante_id), INDEX IDX_CE85783E634807C5 (structure_pilote_id), UNIQUE INDEX uniq_sous_composante_code (composante_id, code, supprime_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE structures (sigle VARCHAR(20) NOT NULL, denomination VARCHAR(255) NOT NULL, type VARCHAR(30) NOT NULL, telephone VARCHAR(40) DEFAULT NULL, email VARCHAR(160) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, actif TINYINT NOT NULL, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, cree_le DATETIME(3), modifie_le DATETIME(3), supprime_le DATETIME, UNIQUE INDEX UNIQ_5BBEC55AD17F50A6 (uuid), UNIQUE INDEX uniq_structure_sigle (sigle, supprime_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur_roles (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, utilisateur_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL, structure_id BIGINT UNSIGNED DEFAULT NULL, INDEX IDX_70FB7619FB88E14F (utilisateur_id), INDEX IDX_70FB7619D60322AC (role_id), INDEX IDX_70FB76192534008B (structure_id), UNIQUE INDEX uniq_utilisateur_role_structure (utilisateur_id, role_id, structure_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateurs (email VARCHAR(180) NOT NULL, mot_de_passe_hash VARCHAR(255) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, fonction VARCHAR(120) DEFAULT NULL, actif TINYINT NOT NULL, doit_changer_mdp TINYINT NOT NULL, derniere_connexion DATETIME, id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, cree_le DATETIME(3), modifie_le DATETIME(3), supprime_le DATETIME, structure_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_497B315EE7927C74 (email), UNIQUE INDEX UNIQ_497B315ED17F50A6 (uuid), INDEX IDX_497B315E2534008B (structure_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activites ADD CONSTRAINT FK_766B5EB59175F422 FOREIGN KEY (sous_composante_id) REFERENCES sous_composantes (id)');
        $this->addSql('ALTER TABLE activites ADD CONSTRAINT FK_766B5EB5AD41CC3B FOREIGN KEY (structure_responsable_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE composantes ADD CONSTRAINT FK_8EBC0B0DC18272 FOREIGN KEY (projet_id) REFERENCES projets (id)');
        $this->addSql('ALTER TABLE composantes ADD CONSTRAINT FK_8EBC0B0D634807C5 FOREIGN KEY (structure_pilote_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE depenses ADD CONSTRAINT FK_EE350ECBD30F6F97 FOREIGN KEY (engagement_id) REFERENCES engagements (id)');
        $this->addSql('ALTER TABLE depenses ADD CONSTRAINT FK_EE350ECBFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE depenses ADD CONSTRAINT FK_EE350ECB6AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE dotations ADD CONSTRAINT FK_E474AE29B0F88B1 FOREIGN KEY (activite_id) REFERENCES activites (id)');
        $this->addSql('ALTER TABLE dotations ADD CONSTRAINT FK_E474AE289D40298 FOREIGN KEY (exercice_id) REFERENCES exercices (id)');
        $this->addSql('ALTER TABLE dotations ADD CONSTRAINT FK_E474AE2FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE engagements ADD CONSTRAINT FK_1A0DD10C9B0F88B1 FOREIGN KEY (activite_id) REFERENCES activites (id)');
        $this->addSql('ALTER TABLE engagements ADD CONSTRAINT FK_1A0DD10C89D40298 FOREIGN KEY (exercice_id) REFERENCES exercices (id)');
        $this->addSql('ALTER TABLE engagements ADD CONSTRAINT FK_1A0DD10CFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE engagements ADD CONSTRAINT FK_1A0DD10C6AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE exercices ADD CONSTRAINT FK_1387EAE1C18272 FOREIGN KEY (projet_id) REFERENCES projets (id)');
        $this->addSql('ALTER TABLE indicateur_desagregations ADD CONSTRAINT FK_6FCBD1B362944DBD FOREIGN KEY (indicateur_valeur_id) REFERENCES indicateur_valeurs (id)');
        $this->addSql('ALTER TABLE indicateur_valeurs ADD CONSTRAINT FK_6E1E7B03DA3B8F3D FOREIGN KEY (indicateur_id) REFERENCES indicateurs (id)');
        $this->addSql('ALTER TABLE indicateur_valeurs ADD CONSTRAINT FK_6E1E7B03B0F809FE FOREIGN KEY (saisi_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE indicateur_valeurs ADD CONSTRAINT FK_6E1E7B036AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE indicateurs ADD CONSTRAINT FK_BE781C5AC18272 FOREIGN KEY (projet_id) REFERENCES projets (id)');
        $this->addSql('ALTER TABLE indicateurs ADD CONSTRAINT FK_BE781C5AAC12F1AD FOREIGN KEY (composante_id) REFERENCES composantes (id)');
        $this->addSql('ALTER TABLE indicateurs ADD CONSTRAINT FK_BE781C5AAD41CC3B FOREIGN KEY (structure_responsable_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE journal_activite ADD CONSTRAINT FK_68C5F8ACFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE parametres ADD CONSTRAINT FK_1A79799D553B2554 FOREIGN KEY (modifie_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE pieces_jointes ADD CONSTRAINT FK_A252649BFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE points_focaux ADD CONSTRAINT FK_F82349692534008B FOREIGN KEY (structure_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE points_focaux ADD CONSTRAINT FK_F8234969FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE rapports_periodiques ADD CONSTRAINT FK_4A93E5D12534008B FOREIGN KEY (structure_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE rapports_periodiques ADD CONSTRAINT FK_4A93E5D189D40298 FOREIGN KEY (exercice_id) REFERENCES exercices (id)');
        $this->addSql('ALTER TABLE rapports_periodiques ADD CONSTRAINT FK_4A93E5D1A3741A05 FOREIGN KEY (piece_jointe_id) REFERENCES pieces_jointes (id)');
        $this->addSql('ALTER TABLE realisations ADD CONSTRAINT FK_FC5C476DDB36FB82 FOREIGN KEY (sous_activite_id) REFERENCES sous_activites (id)');
        $this->addSql('ALTER TABLE realisations ADD CONSTRAINT FK_FC5C476DFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6D60322AC FOREIGN KEY (role_id) REFERENCES roles (id)');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6FED90CCA FOREIGN KEY (permission_id) REFERENCES permissions (id)');
        $this->addSql('ALTER TABLE sous_activites ADD CONSTRAINT FK_6D62B5FA9B0F88B1 FOREIGN KEY (activite_id) REFERENCES activites (id)');
        $this->addSql('ALTER TABLE sous_activites ADD CONSTRAINT FK_6D62B5FAAD41CC3B FOREIGN KEY (structure_responsable_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE sous_composantes ADD CONSTRAINT FK_CE85783EAC12F1AD FOREIGN KEY (composante_id) REFERENCES composantes (id)');
        $this->addSql('ALTER TABLE sous_composantes ADD CONSTRAINT FK_CE85783E634807C5 FOREIGN KEY (structure_pilote_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE utilisateur_roles ADD CONSTRAINT FK_70FB7619FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE utilisateur_roles ADD CONSTRAINT FK_70FB7619D60322AC FOREIGN KEY (role_id) REFERENCES roles (id)');
        $this->addSql('ALTER TABLE utilisateur_roles ADD CONSTRAINT FK_70FB76192534008B FOREIGN KEY (structure_id) REFERENCES structures (id)');
        $this->addSql('ALTER TABLE utilisateurs ADD CONSTRAINT FK_497B315E2534008B FOREIGN KEY (structure_id) REFERENCES structures (id)');

        // Enums métier représentés en VARCHAR + enum PHP natif plutôt qu'en
        // ENUM MySQL (décision actée avec l'ANSD) : le CHECK reproduit la
        // même garantie d'intégrité en base.
        $this->addSql("ALTER TABLE exercices ADD CONSTRAINT chk_exercice_statut CHECK (statut IN ('ouvert', 'cloture'))");
        $this->addSql("ALTER TABLE activites ADD CONSTRAINT chk_activite_statut CHECK (statut IN ('planifiee', 'en_cours', 'terminee', 'suspendue', 'annulee'))");
        $this->addSql("ALTER TABLE sous_activites ADD CONSTRAINT chk_sous_activite_statut CHECK (statut IN ('planifiee', 'en_cours', 'terminee', 'suspendue', 'annulee'))");
        $this->addSql('ALTER TABLE sous_activites ADD CONSTRAINT chk_sous_activite_avancement CHECK (avancement BETWEEN 0 AND 100)');
        $this->addSql("ALTER TABLE engagements ADD CONSTRAINT chk_engagement_nature CHECK (nature IN ('travaux', 'fournitures', 'prestation_intellectuelle', 'fonctionnement'))");
        $this->addSql("ALTER TABLE engagements ADD CONSTRAINT chk_engagement_statut CHECK (statut IN ('brouillon', 'valide', 'annule'))");
        $this->addSql("ALTER TABLE depenses ADD CONSTRAINT chk_depense_statut CHECK (statut IN ('brouillon', 'valide', 'annule'))");
        $this->addSql("ALTER TABLE indicateurs ADD CONSTRAINT chk_indicateur_type CHECK (type IN ('IOD', 'IRI'))");
        $this->addSql("ALTER TABLE indicateurs ADD CONSTRAINT chk_indicateur_sens CHECK (sens IN ('croissant', 'decroissant'))");
        $this->addSql("ALTER TABLE indicateurs ADD CONSTRAINT chk_indicateur_frequence CHECK (frequence IN ('mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'))");
        $this->addSql("ALTER TABLE indicateur_valeurs ADD CONSTRAINT chk_indicateur_valeur_statut CHECK (statut IN ('brouillon', 'valide'))");
        $this->addSql("ALTER TABLE structures ADD CONSTRAINT chk_structure_type CHECK (type IN ('chef_de_file', 'direction_ansd', 'cellule_ansd', 'partenaire_national', 'partenaire_technique', 'partenaire_formation', 'bailleur'))");
        $this->addSql("ALTER TABLE rapports_periodiques ADD CONSTRAINT chk_rapport_periode CHECK (periode IN ('T1', 'T2', 'T3', 'T4'))");
        $this->addSql("ALTER TABLE rapports_periodiques ADD CONSTRAINT chk_rapport_statut CHECK (statut IN ('attendu', 'transmis', 'valide', 'rejete'))");

        // Vues d'agrégation (§ 4.7) : n'incluent que les engagements et
        // dépenses en statut "valide". Les dépenses sont sommées dans une
        // sous-requête séparée pour éviter le piège classique — une jointure
        // directe activités → engagements → dépenses avec deux SUM()
        // multiplierait le montant engagé par le nombre de dépenses.
        $this->addSql(<<<'SQL'
            CREATE VIEW v_execution_activite AS
            SELECT
                a.id AS activite_id,
                d.exercice_id AS exercice_id,
                COALESCE(d.montant, 0) AS notifie,
                COALESCE(eng.total_engage, 0) AS engage,
                COALESCE(dep.total_depense, 0) AS depense
            FROM activites a
            LEFT JOIN dotations d ON d.activite_id = a.id
            LEFT JOIN (
                SELECT e.activite_id, e.exercice_id, SUM(e.montant) AS total_engage
                FROM engagements e
                WHERE e.statut = 'valide' AND e.supprime_le IS NULL
                GROUP BY e.activite_id, e.exercice_id
            ) eng ON eng.activite_id = a.id AND eng.exercice_id = d.exercice_id
            LEFT JOIN (
                SELECT e.activite_id, e.exercice_id, SUM(dp.montant) AS total_depense
                FROM depenses dp
                INNER JOIN engagements e ON e.id = dp.engagement_id
                WHERE dp.statut = 'valide' AND dp.supprime_le IS NULL
                GROUP BY e.activite_id, e.exercice_id
            ) dep ON dep.activite_id = a.id AND dep.exercice_id = d.exercice_id
            WHERE a.supprime_le IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE VIEW v_execution_sous_composante AS
            SELECT
                sc.id AS sous_composante_id,
                v.exercice_id AS exercice_id,
                SUM(v.notifie) AS notifie,
                SUM(v.engage) AS engage,
                SUM(v.depense) AS depense
            FROM sous_composantes sc
            INNER JOIN activites a ON a.sous_composante_id = sc.id AND a.supprime_le IS NULL
            INNER JOIN v_execution_activite v ON v.activite_id = a.id
            WHERE sc.supprime_le IS NULL
            GROUP BY sc.id, v.exercice_id
            SQL);

        $this->addSql(<<<'SQL'
            CREATE VIEW v_execution_composante AS
            SELECT
                c.id AS composante_id,
                v.exercice_id AS exercice_id,
                SUM(v.notifie) AS notifie,
                SUM(v.engage) AS engage,
                SUM(v.depense) AS depense
            FROM composantes c
            INNER JOIN sous_composantes sc ON sc.composante_id = c.id AND sc.supprime_le IS NULL
            INNER JOIN v_execution_sous_composante v ON v.sous_composante_id = sc.id
            WHERE c.supprime_le IS NULL
            GROUP BY c.id, v.exercice_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Les vues doivent être supprimées avant les tables qu'elles référencent.
        $this->addSql('DROP VIEW v_execution_composante');
        $this->addSql('DROP VIEW v_execution_sous_composante');
        $this->addSql('DROP VIEW v_execution_activite');

        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activites DROP FOREIGN KEY FK_766B5EB59175F422');
        $this->addSql('ALTER TABLE activites DROP FOREIGN KEY FK_766B5EB5AD41CC3B');
        $this->addSql('ALTER TABLE composantes DROP FOREIGN KEY FK_8EBC0B0DC18272');
        $this->addSql('ALTER TABLE composantes DROP FOREIGN KEY FK_8EBC0B0D634807C5');
        $this->addSql('ALTER TABLE depenses DROP FOREIGN KEY FK_EE350ECBD30F6F97');
        $this->addSql('ALTER TABLE depenses DROP FOREIGN KEY FK_EE350ECBFC29C013');
        $this->addSql('ALTER TABLE depenses DROP FOREIGN KEY FK_EE350ECB6AF12ED9');
        $this->addSql('ALTER TABLE dotations DROP FOREIGN KEY FK_E474AE29B0F88B1');
        $this->addSql('ALTER TABLE dotations DROP FOREIGN KEY FK_E474AE289D40298');
        $this->addSql('ALTER TABLE dotations DROP FOREIGN KEY FK_E474AE2FC29C013');
        $this->addSql('ALTER TABLE engagements DROP FOREIGN KEY FK_1A0DD10C9B0F88B1');
        $this->addSql('ALTER TABLE engagements DROP FOREIGN KEY FK_1A0DD10C89D40298');
        $this->addSql('ALTER TABLE engagements DROP FOREIGN KEY FK_1A0DD10CFC29C013');
        $this->addSql('ALTER TABLE engagements DROP FOREIGN KEY FK_1A0DD10C6AF12ED9');
        $this->addSql('ALTER TABLE exercices DROP FOREIGN KEY FK_1387EAE1C18272');
        $this->addSql('ALTER TABLE indicateur_desagregations DROP FOREIGN KEY FK_6FCBD1B362944DBD');
        $this->addSql('ALTER TABLE indicateur_valeurs DROP FOREIGN KEY FK_6E1E7B03DA3B8F3D');
        $this->addSql('ALTER TABLE indicateur_valeurs DROP FOREIGN KEY FK_6E1E7B03B0F809FE');
        $this->addSql('ALTER TABLE indicateur_valeurs DROP FOREIGN KEY FK_6E1E7B036AF12ED9');
        $this->addSql('ALTER TABLE indicateurs DROP FOREIGN KEY FK_BE781C5AC18272');
        $this->addSql('ALTER TABLE indicateurs DROP FOREIGN KEY FK_BE781C5AAC12F1AD');
        $this->addSql('ALTER TABLE indicateurs DROP FOREIGN KEY FK_BE781C5AAD41CC3B');
        $this->addSql('ALTER TABLE journal_activite DROP FOREIGN KEY FK_68C5F8ACFB88E14F');
        $this->addSql('ALTER TABLE parametres DROP FOREIGN KEY FK_1A79799D553B2554');
        $this->addSql('ALTER TABLE pieces_jointes DROP FOREIGN KEY FK_A252649BFC29C013');
        $this->addSql('ALTER TABLE points_focaux DROP FOREIGN KEY FK_F82349692534008B');
        $this->addSql('ALTER TABLE points_focaux DROP FOREIGN KEY FK_F8234969FB88E14F');
        $this->addSql('ALTER TABLE rapports_periodiques DROP FOREIGN KEY FK_4A93E5D12534008B');
        $this->addSql('ALTER TABLE rapports_periodiques DROP FOREIGN KEY FK_4A93E5D189D40298');
        $this->addSql('ALTER TABLE rapports_periodiques DROP FOREIGN KEY FK_4A93E5D1A3741A05');
        $this->addSql('ALTER TABLE realisations DROP FOREIGN KEY FK_FC5C476DDB36FB82');
        $this->addSql('ALTER TABLE realisations DROP FOREIGN KEY FK_FC5C476DFC29C013');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6D60322AC');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6FED90CCA');
        $this->addSql('ALTER TABLE sous_activites DROP FOREIGN KEY FK_6D62B5FA9B0F88B1');
        $this->addSql('ALTER TABLE sous_activites DROP FOREIGN KEY FK_6D62B5FAAD41CC3B');
        $this->addSql('ALTER TABLE sous_composantes DROP FOREIGN KEY FK_CE85783EAC12F1AD');
        $this->addSql('ALTER TABLE sous_composantes DROP FOREIGN KEY FK_CE85783E634807C5');
        $this->addSql('ALTER TABLE utilisateur_roles DROP FOREIGN KEY FK_70FB7619FB88E14F');
        $this->addSql('ALTER TABLE utilisateur_roles DROP FOREIGN KEY FK_70FB7619D60322AC');
        $this->addSql('ALTER TABLE utilisateur_roles DROP FOREIGN KEY FK_70FB76192534008B');
        $this->addSql('ALTER TABLE utilisateurs DROP FOREIGN KEY FK_497B315E2534008B');
        $this->addSql('DROP TABLE activites');
        $this->addSql('DROP TABLE composantes');
        $this->addSql('DROP TABLE depenses');
        $this->addSql('DROP TABLE dotations');
        $this->addSql('DROP TABLE engagements');
        $this->addSql('DROP TABLE exercices');
        $this->addSql('DROP TABLE indicateur_desagregations');
        $this->addSql('DROP TABLE indicateur_valeurs');
        $this->addSql('DROP TABLE indicateurs');
        $this->addSql('DROP TABLE journal_activite');
        $this->addSql('DROP TABLE parametres');
        $this->addSql('DROP TABLE permissions');
        $this->addSql('DROP TABLE pieces_jointes');
        $this->addSql('DROP TABLE points_focaux');
        $this->addSql('DROP TABLE projets');
        $this->addSql('DROP TABLE rapports_periodiques');
        $this->addSql('DROP TABLE realisations');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE sous_activites');
        $this->addSql('DROP TABLE sous_composantes');
        $this->addSql('DROP TABLE structures');
        $this->addSql('DROP TABLE utilisateur_roles');
        $this->addSql('DROP TABLE utilisateurs');
    }
}
