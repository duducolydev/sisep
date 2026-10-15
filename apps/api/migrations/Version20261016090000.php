<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nature des documents joints (rapport, compte-rendu, manuel…), choisie au
 * dépôt — observations des utilisateurs, octobre 2026. VARCHAR + enum PHP +
 * CHECK, jamais d'ENUM MySQL (§ 4 du brief).
 *
 * Nullable, sans reprise : les dépôts antérieurs restent « non précisés »,
 * sauf les rapports périodiques transmis, dont la nature ne fait pas de doute.
 */
final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute pieces_jointes.type_document et type_document_precision.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pieces_jointes ADD type_document VARCHAR(30) DEFAULT NULL, ADD type_document_precision VARCHAR(120) DEFAULT NULL');
        $this->addSql("ALTER TABLE pieces_jointes ADD CONSTRAINT chk_piece_jointe_type_document CHECK (type_document IS NULL OR type_document IN ('rapport', 'compte_rendu', 'manuel', 'proces_verbal', 'termes_reference', 'note', 'presentation', 'contrat', 'facture', 'liste_presence', 'photo', 'correspondance', 'autre'))");
        $this->addSql("UPDATE pieces_jointes SET type_document = 'rapport' WHERE entite_type = 'rapport_periodique'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pieces_jointes DROP CHECK chk_piece_jointe_type_document');
        $this->addSql('ALTER TABLE pieces_jointes DROP type_document, DROP type_document_precision');
    }
}
