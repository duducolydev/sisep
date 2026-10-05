<?php

declare(strict_types=1);

namespace App\Export;

use App\Query\BudgetExecutionReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * GET /exports/budget.xlsx?exercice=&composante= (§ 6.2 du brief). Deux
 * feuilles : synthèse par composante, détail par activité — mêmes chiffres
 * que la page Budget (§ 2.3), pas de recalcul parallèle.
 */
final class BudgetXlsxExporter
{
    public function __construct(private readonly BudgetExecutionReader $reader)
    {
    }

    public function exporter(int $projetId, int $exerciceId, int $annee, ?int $composanteId): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        $feuilleComposantes = $spreadsheet->getActiveSheet();
        $feuilleComposantes->setTitle('Synthèse par composante');
        $this->remplirFeuille($feuilleComposantes, $this->reader->lire($projetId, $exerciceId, 'composante', $composanteId), $annee);

        $feuilleActivites = $spreadsheet->createSheet();
        $feuilleActivites->setTitle('Détail par activité');
        $this->remplirFeuille($feuilleActivites, $this->reader->lire($projetId, $exerciceId, 'activite', $composanteId), $annee);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @param list<array<string, mixed>> $lignes */
    private function remplirFeuille(Worksheet $feuille, array $lignes, int $annee): void
    {
        $entetes = ['Code', 'Libellé', 'Notifié (FCFA)', 'Engagé (FCFA)', 'Dépensé (FCFA)', 'Disponible (FCFA)', "Taux d'exécution"];
        $feuille->fromArray([["Exercice {$annee}"]], null, 'A1');
        $feuille->getStyle('A1')->getFont()->setBold(true);
        $feuille->fromArray([$entetes], null, 'A3');
        $feuille->getStyle('A3:G3')->getFont()->setBold(true);
        $feuille->getStyle('A3:G3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3EDF5');

        $ligne = 4;
        foreach ($lignes as $donnee) {
            $feuille->fromArray([[
                $donnee['code'],
                $donnee['libelle'],
                $donnee['notifie'],
                $donnee['engage'],
                $donnee['depense'],
                $donnee['disponible'],
                $donnee['tauxExecution'],
            ]], null, "A{$ligne}");
            ++$ligne;
        }

        $derniereLigne = max(3, $ligne - 1);
        $feuille->getStyle("C4:F{$derniereLigne}")->getNumberFormat()->setFormatCode('#,##0');
        $feuille->getStyle("G4:G{$derniereLigne}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
        $feuille->getStyle('B:B')->getAlignment()->setWrapText(true);

        foreach (['A' => 12, 'B' => 48, 'C' => 18, 'D' => 18, 'E' => 18, 'F' => 18, 'G' => 14] as $colonne => $largeur) {
            $feuille->getColumnDimension($colonne)->setWidth($largeur);
        }
        $feuille->getStyle('C1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}
