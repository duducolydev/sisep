<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Période de mobilisation des effectifs d'enquêteurs (demande du
 * 05/10/2026) : l'année seule ne dit pas quand la collecte a lieu, et le
 * calendrier est souvent arrêté après la programmation de l'effectif — d'où
 * deux colonnes nullables.
 *
 * Le diff Doctrine proposait en plus une dérive préexistante (DATETIME(3)
 * sur une quinzaine de tables, valeurs par défaut des colonnes numériques) :
 * elle est volontairement laissée de côté, c'est un sujet à part.
 */
final class Version20261005154521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute la période de mobilisation (date_debut, date_fin) aux effectifs d'enquêteurs.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE effectifs_enqueteurs ADD date_debut DATE DEFAULT NULL, ADD date_fin DATE DEFAULT NULL');

        // Même garde que sur les contrats : une mobilisation ne peut pas se
        // terminer avant d'avoir commencé. CHECK posée à la main, le serveur
        // restant la seule autorité sur la cohérence des périodes.
        $this->addSql('ALTER TABLE effectifs_enqueteurs ADD CONSTRAINT ck_effectif_periode CHECK (date_fin IS NULL OR date_debut IS NULL OR date_fin >= date_debut)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE effectifs_enqueteurs DROP CONSTRAINT ck_effectif_periode');
        $this->addSql('ALTER TABLE effectifs_enqueteurs DROP date_debut, DROP date_fin');
    }
}
