<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nomenclature des natures d'engagement revue par les RSE (retours de
 * tests, octobre 2026).
 *
 * Reprise des données existantes : « prestation_intellectuelle » devient
 * « service_consultation » (même nature, nouveau nom) ; « fonctionnement »,
 * éclaté en cinq natures, se range dans « autre_fonctionnement » faute de
 * savoir à laquelle chaque engagement appartient — à reclasser à la main si
 * besoin. Le journal d'activité garde les anciennes valeurs : c'est
 * l'historique de ce qui a été saisi.
 */
final class Version20261015090000 extends AbstractMigration
{
    private const NOUVELLES = "'travaux', 'fournitures', 'acquisition_biens', 'service_consultation', 'service_courant', 'salaire', 'frais_mission', 'atelier', 'remboursement_transport', 'autre_fonctionnement'";
    private const ANCIENNES = "'travaux', 'fournitures', 'prestation_intellectuelle', 'fonctionnement'";

    public function getDescription(): string
    {
        return "Natures d'engagement : nomenclature des RSE (service de consultation, fonctionnement éclaté).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE engagements DROP CHECK chk_engagement_nature');
        $this->addSql("UPDATE engagements SET nature = 'service_consultation' WHERE nature = 'prestation_intellectuelle'");
        $this->addSql("UPDATE engagements SET nature = 'autre_fonctionnement' WHERE nature = 'fonctionnement'");
        $this->addSql('ALTER TABLE engagements ADD CONSTRAINT chk_engagement_nature CHECK (nature IN ('.self::NOUVELLES.'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE engagements DROP CHECK chk_engagement_nature');
        $this->addSql("UPDATE engagements SET nature = 'prestation_intellectuelle' WHERE nature = 'service_consultation'");
        $this->addSql("UPDATE engagements SET nature = 'fonctionnement' WHERE nature IN ('salaire', 'frais_mission', 'atelier', 'remboursement_transport', 'autre_fonctionnement')");
        $this->addSql("UPDATE engagements SET nature = 'fournitures' WHERE nature IN ('acquisition_biens', 'service_courant')");
        $this->addSql('ALTER TABLE engagements ADD CONSTRAINT chk_engagement_nature CHECK (nature IN ('.self::ANCIENNES.'))');
    }
}
