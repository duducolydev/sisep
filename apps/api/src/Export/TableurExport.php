<?php

declare(strict_types=1);

namespace App\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Classeur d'export d'une page : une feuille par tableau, un titre, la
 * ligne des filtres appliqués, puis l'en-tête et les données. Les colonnes
 * sont typées pour rester exploitables dans Excel : montants en nombres
 * entiers (jamais du texte « 1 000 000 FCFA »), taux en pourcentages, dates
 * en vraies dates, filtres automatiques sur l'en-tête.
 */
final class TableurExport
{
    public const TEXTE = 'texte';
    public const FCFA = 'fcfa';
    public const ENTIER = 'entier';
    public const DECIMAL = 'decimal';
    public const TAUX = 'taux';
    public const DATE = 'date';
    public const DATE_HEURE = 'date_heure';

    private const FORMATS = [
        self::FCFA => '#,##0',
        self::ENTIER => '#,##0',
        self::DECIMAL => '#,##0.##',
        self::TAUX => '0.0%',
        self::DATE => 'dd/mm/yyyy',
        self::DATE_HEURE => 'dd/mm/yyyy hh:mm',
    ];

    private readonly Spreadsheet $classeur;
    private bool $premiere = true;

    public function __construct(private readonly string $sousTitre = '')
    {
        $this->classeur = new Spreadsheet();
        $this->classeur->getProperties()->setCreator('SISEP — ANSD')->setTitle('Export SISEP');
    }

    /**
     * @param list<array{0: string, 1: string, 2?: float}> $colonnes [intitulé, type, largeur]
     * @param iterable<list<mixed>>                        $lignes
     */
    public function feuille(string $nom, string $titre, array $colonnes, iterable $lignes): self
    {
        if ($this->premiere) {
            $feuille = $this->classeur->getActiveSheet();
            $this->premiere = false;
        } else {
            $feuille = $this->classeur->createSheet();
        }
        // Excel limite le nom d'onglet à 31 caractères et refuse : \ / ? * [ ]
        $feuille->setTitle(mb_substr((string) preg_replace('#[:\\\\/?*\[\]]#', ' ', $nom), 0, 31));

        $feuille->setCellValue('A1', $titre);
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $feuille->setCellValue('A2', trim('Extrait de SISEP le '.(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')))->format('d/m/Y à H:i').('' !== $this->sousTitre ? ' — '.$this->sousTitre : '')));
        $feuille->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('6B7280');

        $derniere = Coordinate::stringFromColumnIndex(max(1, \count($colonnes)));
        foreach ($colonnes as $index => $colonne) {
            $lettre = Coordinate::stringFromColumnIndex($index + 1);
            $feuille->setCellValue("{$lettre}4", $colonne[0]);
            $feuille->getColumnDimension($lettre)->setWidth($colonne[2] ?? 16);
        }
        $entete = $feuille->getStyle("A4:{$derniere}4");
        $entete->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $entete->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('155E93');
        $entete->getAlignment()->setWrapText(true)->setVertical('center');

        $rang = 5;
        foreach ($lignes as $ligne) {
            foreach ($colonnes as $index => $colonne) {
                $valeur = $ligne[$index] ?? null;
                $cellule = Coordinate::stringFromColumnIndex($index + 1).$rang;
                if (null === $valeur || '' === $valeur) {
                    continue;
                }
                if (\in_array($colonne[1], [self::DATE, self::DATE_HEURE], true)) {
                    $date = $valeur instanceof \DateTimeInterface ? $valeur : new \DateTimeImmutable((string) $valeur);
                    $feuille->setCellValue($cellule, DateExcel::PHPToExcel($date));
                } elseif (self::TEXTE === $colonne[1]) {
                    // Texte explicite : un code « 1.2.10 » ou un numéro de téléphone ne devient pas un nombre.
                    $feuille->setCellValueExplicit($cellule, (string) $valeur, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                } else {
                    $feuille->setCellValue($cellule, \is_numeric($valeur) ? $valeur + 0 : $valeur);
                }
            }
            ++$rang;
        }

        foreach ($colonnes as $index => $colonne) {
            if (isset(self::FORMATS[$colonne[1]])) {
                $lettre = Coordinate::stringFromColumnIndex($index + 1);
                $feuille->getStyle("{$lettre}5:{$lettre}".max(5, $rang - 1))->getNumberFormat()->setFormatCode(self::FORMATS[$colonne[1]]);
            }
        }

        $feuille->setAutoFilter("A4:{$derniere}".max(4, $rang - 1));
        $feuille->freezePane('A5');

        return $this;
    }

    public function reponse(string $nomFichier): Response
    {
        $classeur = $this->classeur;
        $classeur->setActiveSheetIndex(0);
        $reponse = new StreamedResponse(static function () use ($classeur): void {
            (new Xlsx($classeur))->save('php://output');
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', \sprintf('attachment; filename="%s"', $nomFichier));

        return $reponse;
    }

    /** Taux pour une cellule TAUX : fraction (0,45 → 45 %), null conservé (division par zéro, § 5.1). */
    public static function taux(?float $taux): ?float
    {
        return null === $taux ? null : round($taux, 4);
    }
}
