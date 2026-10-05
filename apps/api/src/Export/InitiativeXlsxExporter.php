<?php

declare(strict_types=1);

namespace App\Export;

use App\Http\InitiativeNormalizer;
use App\Repository\InitiativeRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * GET /exports/initiatives.xlsx. Réutilise InitiativeNormalizer : les
 * taux d'avancement et d'exécution PTBA exportés sont exactement ceux
 * affichés dans l'IHM, jamais recalculés en parallèle.
 */
final class InitiativeXlsxExporter
{
    private const LIBELLES_STATUT = [
        'planifiee' => 'Planifiée',
        'en_cours' => 'En cours',
        'a_risque' => 'À risque',
        'en_retard' => 'En retard',
        'terminee' => 'Terminée',
    ];

    public function __construct(
        private readonly InitiativeRepository $initiatives,
        private readonly InitiativeNormalizer $normalizer,
    ) {
    }

    public function exporter(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $feuille = $spreadsheet->getActiveSheet();
        $feuille->setTitle('Suivi des projets');

        $entetes = ['Projet', 'Lien PTBA', 'Activité', 'Responsable(s)', 'Début', 'Échéance', 'Statut', "Taux d'avancement", "Taux d'exécution PTBA"];
        $feuille->fromArray([$entetes], null, 'A1');
        $feuille->getStyle('A1:I1')->getFont()->setBold(true);
        $feuille->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3EDF5');

        $ligne = 2;
        foreach ($this->initiatives->listerNonSupprimees() as $initiative) {
            $donnee = $this->normalizer->normaliser($initiative);
            $lienPtba = null !== $donnee['lienPtba']
                ? \sprintf('%s — %s', $donnee['lienPtba']['code'], $donnee['lienPtba']['libelle'])
                : 'Indépendant';
            $tauxPtba = null !== $donnee['lienPtba'] && null !== $donnee['lienPtba']['tauxExecutionPtba']
                ? round($donnee['lienPtba']['tauxExecutionPtba'] * 100, 1).' %'
                : '—';

            if ([] === $donnee['activites']) {
                $feuille->fromArray([[$donnee['libelle'], $lienPtba, '—', '—', '—', '—', '—', '—', $tauxPtba]], null, "A{$ligne}");
                ++$ligne;
                continue;
            }

            foreach ($donnee['activites'] as $activite) {
                $responsables = implode(', ', array_map(static fn ($r) => $r['nomComplet'], $activite['responsables']));
                $feuille->fromArray([[
                    $donnee['libelle'],
                    $lienPtba,
                    $activite['libelle'],
                    '' !== $responsables ? $responsables : '—',
                    $activite['dateDebut'] ?? '—',
                    $activite['echeance'] ?? '—',
                    self::LIBELLES_STATUT[$activite['statut']] ?? $activite['statut'],
                    null !== $activite['avancement'] ? round($activite['avancement'] * 100, 1).' %' : '—',
                    $tauxPtba,
                ]], null, "A{$ligne}");
                ++$ligne;
            }
        }

        foreach (['A' => 24, 'B' => 24, 'C' => 30, 'D' => 24, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 16, 'I' => 18] as $colonne => $largeur) {
            $feuille->getColumnDimension($colonne)->setWidth($largeur);
        }

        return $spreadsheet;
    }
}
