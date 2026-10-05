<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Observations et pièces jointes sur une InitiativeActivite/TacheInitiative
 * (table notes_initiative, polymorphe comme pieces_jointes), et date de
 * clôture réelle d'une InitiativeActivite (date_reelle, jamais renseignée
 * automatiquement avant, posée par InitiativeActivite::definirStatut()).
 * Les lignes `CHANGE ... DATETIME(3)` générées par
 * `doctrine:migrations:diff` sur des tables non liées ont été retirées,
 * même dérive de précision préexistante déjà écartée dans les deux
 * migrations précédentes de ce module.
 */
final class Version20260828152335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Observations, pièces jointes et date de clôture réelle sur le suivi des projets.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notes_initiative (entite_type VARCHAR(40) NOT NULL, entite_id BIGINT UNSIGNED NOT NULL, contenu LONGTEXT NOT NULL, cree_le DATETIME(3), id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, uuid CHAR(36) NOT NULL, cree_par_id BIGINT UNSIGNED DEFAULT NULL, UNIQUE INDEX UNIQ_B9DC2F7AD17F50A6 (uuid), INDEX IDX_B9DC2F7AFC29C013 (cree_par_id), INDEX idx_note_initiative_entite (entite_type, entite_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notes_initiative ADD CONSTRAINT FK_B9DC2F7AFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateurs (id)');
        $this->addSql('ALTER TABLE initiative_activites ADD date_reelle DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notes_initiative DROP FOREIGN KEY FK_B9DC2F7AFC29C013');
        $this->addSql('DROP TABLE notes_initiative');
        $this->addSql('ALTER TABLE initiative_activites DROP date_reelle');
    }
}
