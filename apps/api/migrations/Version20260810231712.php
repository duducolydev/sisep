<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810231712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute rapports_periodiques.derniere_relance_le (§ 2.5 : horodatage de l'action \"relancer les retardataires\", pas d'envoi de courriel réel — § 11 du brief).";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              dotations
            CHANGE
              cree_le cree_le DATETIME(3),
            CHANGE
              modifie_le modifie_le DATETIME(3)
        SQL);
        $this->addSql('ALTER TABLE journal_activite CHANGE cree_le cree_le DATETIME(3)');
        $this->addSql('ALTER TABLE pieces_jointes CHANGE cree_le cree_le DATETIME(3)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              projets
            CHANGE
              cree_le cree_le DATETIME(3),
            CHANGE
              modifie_le modifie_le DATETIME(3)
        SQL);
        $this->addSql('ALTER TABLE rapports_periodiques ADD derniere_relance_le DATETIME');
        $this->addSql('ALTER TABLE realisations CHANGE cree_le cree_le DATETIME(3)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              structures
            CHANGE
              cree_le cree_le DATETIME(3),
            CHANGE
              modifie_le modifie_le DATETIME(3)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              utilisateurs
            CHANGE
              cree_le cree_le DATETIME(3),
            CHANGE
              modifie_le modifie_le DATETIME(3)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE
              dotations
            CHANGE
              cree_le cree_le DATETIME DEFAULT NULL,
            CHANGE
              modifie_le modifie_le DATETIME DEFAULT NULL
        SQL);
        $this->addSql('ALTER TABLE journal_activite CHANGE cree_le cree_le DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE pieces_jointes CHANGE cree_le cree_le DATETIME DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              projets
            CHANGE
              cree_le cree_le DATETIME DEFAULT NULL,
            CHANGE
              modifie_le modifie_le DATETIME DEFAULT NULL
        SQL);
        $this->addSql('ALTER TABLE rapports_periodiques DROP derniere_relance_le');
        $this->addSql('ALTER TABLE realisations CHANGE cree_le cree_le DATETIME DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              structures
            CHANGE
              cree_le cree_le DATETIME DEFAULT NULL,
            CHANGE
              modifie_le modifie_le DATETIME DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              utilisateurs
            CHANGE
              cree_le cree_le DATETIME DEFAULT NULL,
            CHANGE
              modifie_le modifie_le DATETIME DEFAULT NULL
        SQL);
    }
}
