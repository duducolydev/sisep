<?php

declare(strict_types=1);

namespace App\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Modèle Excel téléchargeable d'un import : la feuille « Données » à
 * remplir (en-têtes, listes déroulantes alimentées par les référentiels du
 * moment, format des dates), et une feuille « Instructions » qui décrit
 * chaque colonne. Les listes vivent dans une feuille masquée : un sigle de
 * structure ajouté demain apparaîtra dans le prochain modèle téléchargé.
 */
final class ModeleImportXlsx
{
    private const LIGNES_PREPAREES = 500;

    public function construire(DefinitionImport $definition): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $donnees = $classeur->getActiveSheet();
        $donnees->setTitle('Données');

        $colonnes = $definition->colonnes();
        $listes = $this->ecrireListes($classeur, $definition->listes());

        foreach ($colonnes as $index => $colonne) {
            $lettre = Coordinate::stringFromColumnIndex($index + 1);
            $donnees->setCellValue("{$lettre}1", $colonne->entete.($colonne->obligatoire ? ' *' : ''));
            $donnees->getColumnDimension($lettre)->setWidth($colonne->largeur);

            $plage = \sprintf('%s2:%s%d', $lettre, $lettre, self::LIGNES_PREPAREES + 1);
            if ($colonne->date) {
                $donnees->getStyle($plage)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            } else {
                // Texte : un téléphone ou un code « 1.2.10 » ne doit pas devenir un nombre.
                $donnees->getStyle($plage)->getNumberFormat()->setFormatCode('@');
            }

            if (null !== $colonne->liste && isset($listes[$colonne->liste])) {
                $validation = new DataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_STOP);
                $validation->setAllowBlank(!$colonne->obligatoire);
                $validation->setShowDropDown(true);
                $validation->setShowErrorMessage(true);
                $validation->setErrorTitle('Valeur non prévue');
                $validation->setError('Choisissez une valeur de la liste.');
                $validation->setFormula1($listes[$colonne->liste]);
                $donnees->setDataValidation($plage, $validation);
            }
        }

        $derniere = Coordinate::stringFromColumnIndex(\count($colonnes));
        $entete = $donnees->getStyle("A1:{$derniere}1");
        $entete->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $entete->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('155E93');
        $donnees->freezePane('A2');

        $this->ecrireInstructions($classeur, $definition);
        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    /**
     * @param array<string, list<string>> $listes
     *
     * @return array<string, string> nom de liste => plage absolue pour la validation
     */
    private function ecrireListes(Spreadsheet $classeur, array $listes): array
    {
        $feuille = new Worksheet($classeur, 'Listes');
        $classeur->addSheet($feuille);
        $feuille->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $plages = [];
        $index = 1;
        foreach ($listes as $nom => $valeurs) {
            if ([] === $valeurs) {
                continue;
            }
            $lettre = Coordinate::stringFromColumnIndex($index);
            $feuille->setCellValue("{$lettre}1", $nom);
            foreach ($valeurs as $rang => $valeur) {
                $feuille->setCellValueExplicit($lettre.($rang + 2), $valeur, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $plages[$nom] = \sprintf('Listes!$%s$2:$%s$%d', $lettre, $lettre, \count($valeurs) + 1);
            ++$index;
        }

        return $plages;
    }

    private function ecrireInstructions(Spreadsheet $classeur, DefinitionImport $definition): void
    {
        $feuille = new Worksheet($classeur, 'Instructions');
        $classeur->addSheet($feuille, 1);

        $feuille->setCellValue('A1', 'Import — '.$definition->titre());
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $feuille->fromArray([
            ['Remplissez la feuille « Données », une ligne par enregistrement, sans modifier la ligne d\'en-tête. Les colonnes marquées * sont obligatoires.'],
            ['Formats acceptés à l\'import : ce modèle (.xlsx), .xls ou .csv (séparateur ; ou ,), 5 Mo et 2 000 lignes au plus.'],
            ['Doublons : une ligne qui répète une ligne précédente du fichier est ignorée ; un enregistrement déjà présent est ignoré, ou mis à jour si vous le demandez à l\'import.'],
            ['Un aperçu détaille chaque ligne avant tout enregistrement : rien n\'est écrit tant que vous ne confirmez pas.'],
        ], null, 'A2');

        $feuille->fromArray([['Colonne', 'Obligatoire', 'Description', 'Exemple']], null, 'A7');
        $feuille->getStyle('A7:D7')->getFont()->setBold(true);
        $feuille->getStyle('A7:D7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3EDF5');

        $ligne = 8;
        foreach ($definition->colonnes() as $colonne) {
            $feuille->fromArray([[$colonne->entete, $colonne->obligatoire ? 'Oui' : 'Non', $colonne->description, $colonne->exemple]], null, "A{$ligne}");
            ++$ligne;
        }

        foreach (['A' => 26, 'B' => 12, 'C' => 70, 'D' => 26] as $colonne => $largeur) {
            $feuille->getColumnDimension($colonne)->setWidth($largeur);
        }
        $feuille->getStyle('C:C')->getAlignment()->setWrapText(true);
    }
}
