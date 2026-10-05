<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901104305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Statut du module Projets entièrement calculé (StatutInitiativeCalculator) : plus de statut saisi ni de date de clôture stockée.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE initiative_activites DROP CONSTRAINT chk_initiative_activite_statut');
        $this->addSql('ALTER TABLE initiative_activites DROP statut, DROP date_reelle');

        $this->addSql('ALTER TABLE taches_initiative DROP CONSTRAINT chk_tache_initiative_statut');
        $this->addSql('ALTER TABLE taches_initiative DROP statut');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE taches_initiative ADD statut VARCHAR(20) NOT NULL DEFAULT 'planifiee'");
        $this->addSql("ALTER TABLE taches_initiative ADD CONSTRAINT chk_tache_initiative_statut CHECK (statut IN ('planifiee', 'en_cours', 'terminee'))");

        $this->addSql("ALTER TABLE initiative_activites ADD statut VARCHAR(20) NOT NULL DEFAULT 'planifiee', ADD date_reelle DATE DEFAULT NULL");
        $this->addSql("ALTER TABLE initiative_activites ADD CONSTRAINT chk_initiative_activite_statut CHECK (statut IN ('planifiee', 'en_cours', 'terminee', 'suspendue', 'annulee'))");
    }
}
