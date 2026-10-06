<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Fabrique un classeur .xlsx minimal pour les tests.
 *
 * Les classeurs réels du projet (état de paie, contacts, PTBA) portent des
 * données nominatives et budgétaires réelles : ils n'entrent pas dans le
 * dépôt. Les tests reconstruisent donc à la main des classeurs qui en
 * reproduisent la forme — en-têtes, sections, lignes de total — sans les
 * données.
 *
 * Seules les deux entrées que LecteurClasseur ouvre sont écrites, et les
 * valeurs sont inline (`t="inlineStr"`) pour éviter d'avoir à construire une
 * table de chaînes partagées. Les nombres sont écrits en cellule numérique,
 * comme Excel le fait.
 */
final class ConstructeurClasseur
{
    /** @var array<string, list<array<string, string>>> */
    private array $feuilles = [];

    /** @param list<array<string, string>> $lignes */
    public function ajouterFeuille(string $nom, array $lignes): self
    {
        $this->feuilles[$nom] = $lignes;

        return $this;
    }

    /** @return string chemin du fichier créé, à supprimer par l'appelant */
    public function ecrire(): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'sisep_classeur_');
        if (false === $chemin) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire.');
        }

        $archive = new \ZipArchive();
        if (true !== $archive->open($chemin, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException("Impossible d'écrire le classeur de test.");
        }

        $onglets = '';
        $relations = '';
        $index = 0;
        foreach ($this->feuilles as $nom => $lignes) {
            ++$index;
            $onglets .= \sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', htmlspecialchars($nom, \ENT_XML1, 'UTF-8'), $index, $index);
            $relations .= \sprintf('<Relationship Id="rId%d" Target="worksheets/sheet%d.xml"/>', $index, $index);
            $archive->addFromString(\sprintf('xl/worksheets/sheet%d.xml', $index), $this->feuille($lignes));
        }

        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook><sheets>'.$onglets.'</sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships>'.$relations.'</Relationships>');
        $archive->close();

        return $chemin;
    }

    /** @param list<array<string, string>> $lignes */
    private function feuille(array $lignes): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData>';

        foreach ($lignes as $index => $cellules) {
            $numero = $index + 1;
            $xml .= \sprintf('<row r="%d">', $numero);
            foreach ($cellules as $colonne => $valeur) {
                $xml .= is_numeric($valeur)
                    ? \sprintf('<c r="%s%d"><v>%s</v></c>', $colonne, $numero, $valeur)
                    : \sprintf('<c r="%s%d" t="inlineStr"><is><t>%s</t></is></c>', $colonne, $numero, htmlspecialchars($valeur, \ENT_XML1, 'UTF-8'));
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }
}
