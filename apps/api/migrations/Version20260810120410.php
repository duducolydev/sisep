<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Verrouillage de compte après 8 échecs de connexion sur 15 min (§ 7.1 du
 * brief). Les autres colonnes que Doctrine proposait ici (CHANGE cree_le /
 * modifie_le sur plusieurs tables) sont un faux positif du comparateur de
 * schéma face aux colonnes déclarées avec columnDefinition: 'DATETIME(3)' —
 * retirées, elles ne correspondent à aucun changement réel.
 */
final class Version20260810120410 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verrouillage de compte après échecs de connexion répétés (§ 7.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateurs ADD echecs_connexion SMALLINT UNSIGNED NOT NULL, ADD dernier_echec_le DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateurs DROP echecs_connexion, DROP dernier_echec_le');
    }
}
