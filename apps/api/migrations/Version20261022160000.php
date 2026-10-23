<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le journal d'activité retenait l'empreinte du mot de passe à chaque
 * changement (avant et après) — lisible par tout titulaire de
 * journal.consulter et exportable. JournalActiviteSubscriber la masque
 * désormais ; cette migration masque celles déjà enregistrées. L'entrée
 * reste : on sait toujours qui a changé de mot de passe, et quand.
 *
 * Irréversible par nature : les empreintes effacées ne se reconstituent pas.
 */
final class Version20261022160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Journal d'activité : masquage des empreintes de mot de passe déjà enregistrées.";
    }

    public function up(Schema $schema): void
    {
        foreach (['avant', 'apres'] as $colonne) {
            $this->addSql(\sprintf(
                "UPDATE journal_activite SET %1\$s = JSON_SET(%1\$s, '$.motDePasseHash', '(masqué)')
                 WHERE entite_type = 'utilisateur'
                   AND JSON_TYPE(JSON_EXTRACT(%1\$s, '$.motDePasseHash')) = 'STRING'",
                $colonne,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : les empreintes masquées ne sont pas restituables.
    }
}
