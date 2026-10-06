<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rh;

use App\Classeur\LecteurClasseur;
use App\Enum\Sexe;
use App\Rh\LecteurClasseurPersonnel;
use PHPUnit\Framework\TestCase;

/**
 * Le classeur de l'UGP est un état de paie mensuel : il contient des
 * informations salariales et nominatives réelles, donc il n'entre pas dans
 * le dépôt. Le test fabrique à la place un classeur minimal qui reproduit
 * les formes que le lecteur doit encaisser : titres de section, lignes de
 * sous-total, cellule parasite sur un titre, date en numéro de série Excel
 * et date saisie à la main.
 */
final class LecteurClasseurPersonnelTest extends TestCase
{
    private string $fichier = '';

    protected function tearDown(): void
    {
        if ('' !== $this->fichier && is_file($this->fichier)) {
            unlink($this->fichier);
        }
    }

    public function testLitLesAgentsAvecLeurOperationEtLeursDates(): void
    {
        $this->fichier = $this->classeur([
            // Deux lignes de titre avant l'en-tête, comme dans le fichier réel.
            ['A' => 'Projet PHASAOC/SN'],
            ['A' => "ETAT RECAPITULATIF DES SOMMES A PAYER POUR LE PERSONNEL TECHNIQUE DU MOIS D'AOUT 2026"],
            ['A' => 'N°', 'B' => 'PRENOMS', 'C' => 'NOM', 'D' => 'FONCTION', 'E' => 'DATE DEBUT CONTRAT', 'F' => 'DATE FIN CONTRAT', 'G' => 'SEXE'],
            // Titre de section traînant une cellule parasite en colonne G.
            ['A' => 'ENQUETE HARMONISEE DE CONDITIONS DE VIE DES MENAGES', 'G' => 'M'],
            ['A' => '1', 'B' => 'Ibrahima', 'C' => 'BALDE', 'D' => "ITS/Assistant chargé d'étude", 'G' => 'M'],
            // 46023 = 01/01/2026 en numérotation Excel ; la fin est saisie à la main.
            ['A' => '2', 'B' => 'Marie Agathe', 'C' => 'SECK', 'D' => 'Ingénieur Statisticien', 'E' => '46023', 'F' => '31/12/2026', 'G' => 'F'],
            ['A' => 'SOUS TOTAL 1'],
            ['A' => 'RGE'],
            // Sexe absent : le lecteur le remonte à null, il ne le devine pas.
            ['A' => '1', 'B' => 'Pathé', 'C' => 'DIAGNE', 'D' => "ISE/Chargé d'études"],
            ['A' => 'TOTAL GENERAL'],
        ]);

        $lignes = (new LecteurClasseurPersonnel(new LecteurClasseur()))->lire($this->fichier);

        self::assertCount(3, $lignes, 'Ni les titres, ni les sous-totaux, ni le total général ne sont des agents.');

        self::assertSame('Ibrahima', $lignes[0]->prenom);
        self::assertSame('BALDE', $lignes[0]->nom);
        self::assertSame(Sexe::MASCULIN, $lignes[0]->sexe);
        self::assertSame('ENQUETE HARMONISEE DE CONDITIONS DE VIE DES MENAGES', $lignes[0]->operation);
        self::assertNull($lignes[0]->dateDebut, 'Aucune date dans le classeur : la convention est appliquée à l\'import, pas ici.');

        self::assertSame('2026-01-01', $lignes[1]->dateDebut?->format('Y-m-d'), 'Un numéro de série Excel doit être converti.');
        self::assertSame('2026-12-31', $lignes[1]->dateFin?->format('Y-m-d'));
        self::assertSame(Sexe::FEMININ, $lignes[1]->sexe);

        self::assertSame('RGE', $lignes[2]->operation, 'Un titre de section ne doit pas être manqué à cause d\'une cellule parasite.');
        self::assertNull($lignes[2]->sexe);
    }

    public function testRefuseUnClasseurSansEnTete(): void
    {
        $this->fichier = $this->classeur([
            ['A' => 'Une feuille sans en-tête'],
            ['A' => '1', 'B' => 'Ibrahima', 'C' => 'BALDE'],
        ]);

        $this->expectException(\RuntimeException::class);
        (new LecteurClasseurPersonnel(new LecteurClasseur()))->lire($this->fichier);
    }

    /**
     * Classeur .xlsx minimal : seules les deux entrées que le lecteur ouvre
     * sont écrites. Les valeurs sont toutes inline (t="inlineStr") pour
     * éviter d'avoir à construire une table de chaînes partagées, sauf les
     * nombres, écrits en cellule numérique comme le fait Excel pour les
     * dates.
     *
     * @param list<array<string, string>> $lignes
     */
    private function classeur(array $lignes): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($lignes as $index => $cellules) {
            $numero = $index + 1;
            $xml .= \sprintf('<row r="%d">', $numero);
            foreach ($cellules as $colonne => $valeur) {
                $xml .= ctype_digit($valeur)
                    ? \sprintf('<c r="%s%d"><v>%s</v></c>', $colonne, $numero, $valeur)
                    : \sprintf('<c r="%s%d" t="inlineStr"><is><t>%s</t></is></c>', $colonne, $numero, htmlspecialchars($valeur, \ENT_XML1, 'UTF-8'));
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';

        $chemin = tempnam(sys_get_temp_dir(), 'sisep_classeur_');
        self::assertNotFalse($chemin);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($chemin, \ZipArchive::OVERWRITE));
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook><sheets><sheet name="ETAT" sheetId="1"/></sheets></workbook>');
        $archive->addFromString('xl/worksheets/sheet1.xml', $xml);
        $archive->close();

        return $chemin;
    }
}
