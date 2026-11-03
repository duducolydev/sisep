<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Membres de l'équipe du projet PHASAOC (03/11/2026) : un repère sur le
 * compte, coché à sa création, pour reconnaître ces personnes dans le
 * système. N'ouvre aucun droit. Les comptes existants partent à « non » ;
 * l'administration les coche un par un.
 */
final class Version20261103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes : repère membre_phasaoc.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateurs ADD membre_phasaoc TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateurs DROP membre_phasaoc');
    }
}
