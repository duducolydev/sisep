<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Intitulé libre sur une pièce jointe, pour la bibliothèque documentaire de
 * l'UGP : un document se cherche par ce qu'il est, pas par le nom que son
 * auteur a donné au fichier.
 *
 * Nullable, donc sans reprise de données : les justificatifs déjà joints aux
 * étapes, aux valeurs d'indicateurs et aux rapports gardent leur seul nom de
 * fichier, qui suffit là où le document se lit à côté de ce qu'il accompagne.
 *
 * Le diff Doctrine proposait en plus une dérive préexistante (DATETIME(3) sur
 * une quinzaine de tables, valeurs par défaut des colonnes numériques) :
 * laissée de côté, c'est un sujet à part.
 */
final class Version20261006101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute pieces_jointes.libelle (intitulé libre, bibliothèque documentaire).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pieces_jointes ADD libelle VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pieces_jointes DROP libelle');
    }
}
