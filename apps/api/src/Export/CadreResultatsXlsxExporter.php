<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Projet;
use App\Http\IndicateurNormalizer;
use App\Repository\IndicateurRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * GET /exports/cadre-resultats.xlsx?exercice= (§ 6.2 du brief). Réutilise
 * IndicateurNormalizer (§ 5.2) : la valeur actuelle et le taux d'atteinte
 * exportés sont exactement ceux affichés dans l'IHM.
 */
final class CadreResultatsXlsxExporter
{
    public function __construct(
        private readonly IndicateurRepository $indicateurs,
        private readonly IndicateurNormalizer $normalizer,
    ) {
    }

    public function exporter(Projet $projet, int $annee): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Cadre de résultats');

        $entetes = ['Code', 'Type', 'Libellé', 'Unité', 'Référence', 'Actuel', 'Cible', "Taux d'atteinte", 'Source'];
        $feuille->fromArray([["Cadre de résultats — exercice {$annee}"]], null, 'A1');
        $feuille->getStyle('A1')->getFont()->setBold(true);
        $feuille->fromArray([$entetes], null, 'A3');
        $feuille->getStyle('A3:I3')->getFont()->setBold(true);
        $feuille->getStyle('A3:I3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3EDF5');

        $ligne = 4;
        foreach ($this->indicateurs->filtrer($projet, null, null) as $indicateur) {
            $donnee = $this->normalizer->normaliser($indicateur);
            $feuille->fromArray([[
                $donnee['code'],
                $donnee['type'],
                $donnee['libelle'],
                $donnee['unite'],
                $donnee['valeurReference'],
                $donnee['actuel'] ?? '—',
                $donnee['valeurCible'],
                null !== $donnee['tauxAtteinte'] ? round($donnee['tauxAtteinte'] * 100, 1).' %' : '—',
                $donnee['source'],
            ]], null, "A{$ligne}");
            ++$ligne;
        }

        foreach (['A' => 12, 'B' => 8, 'C' => 48, 'D' => 12, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 14, 'I' => 30] as $colonne => $largeur) {
            $feuille->getColumnDimension($colonne)->setWidth($largeur);
        }
        $feuille->getStyle('C:C')->getAlignment()->setWrapText(true);

        return $spreadsheet;
    }
}
