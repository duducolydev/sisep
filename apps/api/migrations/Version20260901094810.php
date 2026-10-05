<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901094810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Visibilité des pièces jointes (tous/équipe PHASAOC/sectoriels/agents spécifiques) et unification des statuts de tâche avec ceux d\'activité.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pieces_jointes ADD visibilite VARCHAR(30) NOT NULL DEFAULT 'tous'");
        $this->addSql("ALTER TABLE pieces_jointes ADD CONSTRAINT chk_piece_jointe_visibilite CHECK (visibilite IN ('tous', 'equipe_phasaoc', 'sectoriels', 'agents_specifiques'))");

        $this->addSql(<<<'SQL'
            CREATE TABLE pieces_jointes_visibilite_agents (
              piece_jointe_id BIGINT UNSIGNED NOT NULL,
              utilisateur_id BIGINT UNSIGNED NOT NULL,
              INDEX IDX_2370EB1AA3741A05 (piece_jointe_id),
              INDEX IDX_2370EB1AFB88E14F (utilisateur_id),
              PRIMARY KEY (piece_jointe_id, utilisateur_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE pieces_jointes_visibilite_agents ADD CONSTRAINT FK_2370EB1AA3741A05 FOREIGN KEY (piece_jointe_id) REFERENCES pieces_jointes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pieces_jointes_visibilite_agents ADD CONSTRAINT FK_2370EB1AFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE taches_initiative DROP CONSTRAINT chk_tache_initiative_statut');
        $this->addSql("UPDATE taches_initiative SET statut = 'planifiee' WHERE statut = 'a_faire'");
        $this->addSql("UPDATE taches_initiative SET statut = 'terminee' WHERE statut = 'faite'");
        $this->addSql("ALTER TABLE taches_initiative ADD CONSTRAINT chk_tache_initiative_statut CHECK (statut IN ('planifiee', 'en_cours', 'terminee'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE taches_initiative DROP CONSTRAINT chk_tache_initiative_statut');
        $this->addSql("UPDATE taches_initiative SET statut = 'a_faire' WHERE statut = 'planifiee'");
        $this->addSql("UPDATE taches_initiative SET statut = 'faite' WHERE statut = 'terminee'");
        $this->addSql("ALTER TABLE taches_initiative ADD CONSTRAINT chk_tache_initiative_statut CHECK (statut IN ('a_faire', 'en_cours', 'faite'))");

        $this->addSql('ALTER TABLE pieces_jointes_visibilite_agents DROP FOREIGN KEY FK_2370EB1AA3741A05');
        $this->addSql('ALTER TABLE pieces_jointes_visibilite_agents DROP FOREIGN KEY FK_2370EB1AFB88E14F');
        $this->addSql('DROP TABLE pieces_jointes_visibilite_agents');

        $this->addSql('ALTER TABLE pieces_jointes DROP CONSTRAINT chk_piece_jointe_visibilite');
        $this->addSql('ALTER TABLE pieces_jointes DROP visibilite');
    }
}
