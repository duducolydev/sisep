<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étapes d'une ligne du PTBA (retours des RSE, octobre 2026) : une étape
 * peut appartenir à une sous-activité — RNOCB et ses étapes de recrutement,
 * de formation des enquêteurs, de phase pilote… — en restant rattachée à
 * l'activité parente.
 *
 * Nullable, sans reprise : les étapes existantes restent les étapes propres
 * de leur activité. Le diff Doctrine proposait aussi la dérive préexistante
 * (DATETIME(3), types non signés), laissée de côté comme dans les
 * migrations précédentes.
 */
final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute etapes_activite.sous_activite_id (étapes par ligne du PTBA).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE etapes_activite ADD sous_activite_id BIGINT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE etapes_activite ADD CONSTRAINT FK_EEC746C4DB36FB82 FOREIGN KEY (sous_activite_id) REFERENCES sous_activites (id)');
        $this->addSql('CREATE INDEX IDX_EEC746C4DB36FB82 ON etapes_activite (sous_activite_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE etapes_activite DROP FOREIGN KEY FK_EEC746C4DB36FB82');
        $this->addSql('DROP INDEX IDX_EEC746C4DB36FB82 ON etapes_activite');
        $this->addSql('ALTER TABLE etapes_activite DROP sous_activite_id');
    }
}
