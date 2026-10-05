<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alignement sur le PTBA réel du PHASAOC (CR du 06/08/2026) :
 *  - budget (dotation/engagement) désormais possible au niveau sous-activité,
 *    pas seulement activité — le PTBA chiffre certaines lignes à ce niveau
 *    (ex. RGPH-5, EHCVM) ;
 *  - dotation trimestrielle (q1..q4), en plus du montant annuel ;
 *  - suivi de révision COPIL (montant validé en comité de pilotage, écart
 *    calculé à l'affichage, jamais stocké — § 5.1, et commentaire justificatif
 *    texte, comme la colonne "Commentaires sur les changements" du PTBA) ;
 *  - objectif intermédiaire sur les indicateurs, en plus de la cible finale
 *    (le tableau de bord réel du PHASAOC suit 4 séries : référence, cumulé à
 *    date, objectif intermédiaire, objectif final).
 */
final class Version20260813112200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Budget au niveau sous-activité, trimestrialisation et révision COPIL des dotations, objectif intermédiaire des indicateurs.';
    }

    public function up(Schema $schema): void
    {
        // Les vues dépendent de v_execution_activite, qui change de logique
        // (rollup activité directe + sous-activités) : à recréer en cascade.
        $this->addSql('DROP VIEW v_execution_composante');
        $this->addSql('DROP VIEW v_execution_sous_composante');
        $this->addSql('DROP VIEW v_execution_activite');

        // --- dotations : cible activité OU sous-activité, trimestrialisation, révision COPIL ---
        $this->addSql('ALTER TABLE dotations CHANGE activite_id activite_id BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE dotations ADD sous_activite_id BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE dotations ADD q1 BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE dotations ADD q2 BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE dotations ADD q3 BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE dotations ADD q4 BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE dotations ADD montant_valide_copil BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE dotations ADD commentaire_revision LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE dotations ADD CONSTRAINT FK_E474AE2DB36FB82 FOREIGN KEY (sous_activite_id) REFERENCES sous_activites (id)');
        $this->addSql('ALTER TABLE dotations ADD INDEX IDX_E474AE2DB36FB82 (sous_activite_id)');
        $this->addSql('ALTER TABLE dotations ADD UNIQUE INDEX uniq_dotation_sous_activite_exercice (sous_activite_id, exercice_id)');
        $this->addSql('ALTER TABLE dotations ADD CONSTRAINT chk_dotation_cible CHECK ((activite_id IS NULL) <> (sous_activite_id IS NULL))');

        // --- engagements : cible activité OU sous-activité ---
        $this->addSql('ALTER TABLE engagements CHANGE activite_id activite_id BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE engagements ADD sous_activite_id BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE engagements ADD CONSTRAINT FK_1A0DD10CDB36FB82 FOREIGN KEY (sous_activite_id) REFERENCES sous_activites (id)');
        $this->addSql('ALTER TABLE engagements ADD INDEX idx_engagement_sous_activite_exercice_statut (sous_activite_id, exercice_id, statut)');
        $this->addSql('ALTER TABLE engagements ADD CONSTRAINT chk_engagement_cible CHECK ((activite_id IS NULL) <> (sous_activite_id IS NULL))');

        // --- indicateurs : objectif intermédiaire ---
        $this->addSql('ALTER TABLE indicateurs ADD valeur_intermediaire NUMERIC(18, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE indicateurs ADD date_intermediaire DATE DEFAULT NULL');

        // --- vues : v_execution_sous_activite (nouvelle), v_execution_activite en rollup ---
        $this->addSql(<<<'SQL'
            CREATE VIEW v_execution_sous_activite AS
            SELECT
                sa.id AS sous_activite_id,
                d.exercice_id AS exercice_id,
                COALESCE(d.montant, 0) AS notifie,
                COALESCE(eng.total_engage, 0) AS engage,
                COALESCE(dep.total_depense, 0) AS depense
            FROM sous_activites sa
            LEFT JOIN dotations d ON d.sous_activite_id = sa.id
            LEFT JOIN (
                SELECT e.sous_activite_id, e.exercice_id, SUM(e.montant) AS total_engage
                FROM engagements e
                WHERE e.statut = 'valide' AND e.supprime_le IS NULL AND e.sous_activite_id IS NOT NULL
                GROUP BY e.sous_activite_id, e.exercice_id
            ) eng ON eng.sous_activite_id = sa.id AND eng.exercice_id = d.exercice_id
            LEFT JOIN (
                SELECT e.sous_activite_id, e.exercice_id, SUM(dp.montant) AS total_depense
                FROM depenses dp
                INNER JOIN engagements e ON e.id = dp.engagement_id
                WHERE dp.statut = 'valide' AND dp.supprime_le IS NULL AND e.sous_activite_id IS NOT NULL
                GROUP BY e.sous_activite_id, e.exercice_id
            ) dep ON dep.sous_activite_id = sa.id AND dep.exercice_id = d.exercice_id
            WHERE sa.supprime_le IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE VIEW v_execution_activite AS
            SELECT
                combine.activite_id AS activite_id,
                combine.exercice_id AS exercice_id,
                SUM(combine.notifie) AS notifie,
                SUM(combine.engage) AS engage,
                SUM(combine.depense) AS depense
            FROM (
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
                    WHERE e.statut = 'valide' AND e.supprime_le IS NULL AND e.activite_id IS NOT NULL
                    GROUP BY e.activite_id, e.exercice_id
                ) eng ON eng.activite_id = a.id AND eng.exercice_id = d.exercice_id
                LEFT JOIN (
                    SELECT e.activite_id, e.exercice_id, SUM(dp.montant) AS total_depense
                    FROM depenses dp
                    INNER JOIN engagements e ON e.id = dp.engagement_id
                    WHERE dp.statut = 'valide' AND dp.supprime_le IS NULL AND e.activite_id IS NOT NULL
                    GROUP BY e.activite_id, e.exercice_id
                ) dep ON dep.activite_id = a.id AND dep.exercice_id = d.exercice_id
                WHERE a.supprime_le IS NULL

                UNION ALL

                SELECT
                    sa.activite_id AS activite_id,
                    v.exercice_id AS exercice_id,
                    v.notifie AS notifie,
                    v.engage AS engage,
                    v.depense AS depense
                FROM sous_activites sa
                INNER JOIN activites pa ON pa.id = sa.activite_id AND pa.supprime_le IS NULL
                INNER JOIN v_execution_sous_activite v ON v.sous_activite_id = sa.id
                WHERE sa.supprime_le IS NULL
            ) combine
            GROUP BY combine.activite_id, combine.exercice_id
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
        $this->addSql('DROP VIEW v_execution_composante');
        $this->addSql('DROP VIEW v_execution_sous_composante');
        $this->addSql('DROP VIEW v_execution_activite');
        $this->addSql('DROP VIEW v_execution_sous_activite');

        $this->addSql('ALTER TABLE indicateurs DROP valeur_intermediaire, DROP date_intermediaire');

        $this->addSql('ALTER TABLE engagements DROP CONSTRAINT chk_engagement_cible');
        $this->addSql('ALTER TABLE engagements DROP FOREIGN KEY FK_1A0DD10CDB36FB82');
        $this->addSql('ALTER TABLE engagements DROP INDEX idx_engagement_sous_activite_exercice_statut');
        $this->addSql('ALTER TABLE engagements DROP sous_activite_id');
        $this->addSql('ALTER TABLE engagements CHANGE activite_id activite_id BIGINT UNSIGNED NOT NULL');

        $this->addSql('ALTER TABLE dotations DROP CONSTRAINT chk_dotation_cible');
        $this->addSql('ALTER TABLE dotations DROP FOREIGN KEY FK_E474AE2DB36FB82');
        $this->addSql('ALTER TABLE dotations DROP INDEX uniq_dotation_sous_activite_exercice');
        $this->addSql('ALTER TABLE dotations DROP INDEX IDX_E474AE2DB36FB82');
        $this->addSql('ALTER TABLE dotations DROP sous_activite_id, DROP q1, DROP q2, DROP q3, DROP q4, DROP montant_valide_copil, DROP commentaire_revision');
        $this->addSql('ALTER TABLE dotations CHANGE activite_id activite_id BIGINT UNSIGNED NOT NULL');

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
}
