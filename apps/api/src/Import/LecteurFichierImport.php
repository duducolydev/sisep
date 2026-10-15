<?php

declare(strict_types=1);

namespace App\Import;

use App\Classeur\Normalisation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Lit un fichier d'import déposé par un utilisateur — Excel (.xlsx, .xls) ou
 * CSV — et le ramène à des lignes `cle => valeur texte`, après tous les
 * contrôles qui valent pour le fichier entier :
 *  - taille (5 Mo) et nombre de lignes (2 000) ;
 *  - extension cohérente avec le contenu réel : le type MIME annoncé par le
 *    navigateur ne prouve rien, on lit la signature du fichier ;
 *  - encodage du CSV : UTF-8 (avec ou sans BOM), UTF-16, ou Windows-1252
 *    (Excel français), converti en UTF-8 ; séparateur deviné (; , ou
 *    tabulation) ;
 *  - ligne d'en-tête retrouvée parmi les dix premières, colonnes repérées
 *    par leur intitulé et non par leur position.
 */
final class LecteurFichierImport
{
    public const TAILLE_MAX_OCTETS = 5 * 1024 * 1024;
    public const LIGNES_MAX = 2000;

    /** Types MIME (lus par finfo sur le contenu) admis pour chaque extension. */
    private const MIMES = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'],
    ];

    /**
     * @param list<ColonneImport> $colonnes
     *
     * @return array{format: string, encodage: string|null, lignes: array<int, array<string, string|float|int|null>>, colonnesIgnorees: list<string>}
     */
    public function lire(UploadedFile $fichier, array $colonnes): array
    {
        if (!$fichier->isValid()) {
            throw new ImportInvalideException(\in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'Fichier trop volumineux : 5 Mo au plus.' : "Le fichier n'a pas pu être reçu. Réessayez.");
        }
        if ((int) $fichier->getSize() > self::TAILLE_MAX_OCTETS) {
            throw new ImportInvalideException('Fichier trop volumineux : 5 Mo au plus.');
        }
        if (0 === (int) $fichier->getSize()) {
            throw new ImportInvalideException('Le fichier est vide.');
        }

        $extension = mb_strtolower(pathinfo($fichier->getClientOriginalName(), \PATHINFO_EXTENSION));
        if (!isset(self::MIMES[$extension])) {
            throw new ImportInvalideException('Format non pris en charge : utilisez le modèle Excel (.xlsx), ou un fichier .xls ou .csv.');
        }

        $chemin = $fichier->getPathname();
        $this->verifierContenu($chemin, $extension);

        [$grille, $encodage] = 'csv' === $extension ? $this->grilleCsv($chemin) : [$this->grilleExcel($chemin, $extension), null];

        return ['format' => $extension, 'encodage' => $encodage, ...$this->extraire($grille, $colonnes)];
    }

    /** Signature du fichier contre son extension : un .xlsx qui est en réalité un PDF ou un exécutable est refusé. */
    private function verifierContenu(string $chemin, string $extension): void
    {
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->file($chemin) ?: 'application/octet-stream';
        $entete = (string) file_get_contents($chemin, false, null, 0, 8);

        $signatureOk = match ($extension) {
            'xlsx' => str_starts_with($entete, "PK\x03\x04"),
            'xls' => str_starts_with($entete, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            default => !str_contains((string) file_get_contents($chemin, false, null, 0, 4096), "\0")
                || str_starts_with($entete, "\xFF\xFE") || str_starts_with($entete, "\xFE\xFF"),
        };

        if (!$signatureOk || !\in_array($mime, self::MIMES[$extension], true)) {
            throw new ImportInvalideException(\sprintf('Le contenu du fichier ne correspond pas à un fichier .%s (type détecté : %s).', $extension, $mime));
        }
    }

    /** @return list<list<mixed>> */
    private function grilleExcel(string $chemin, string $extension): array
    {
        try {
            $lecteur = IOFactory::createReader('xlsx' === $extension ? 'Xlsx' : 'Xls');
            $lecteur->setReadDataOnly(true);
            $classeur = $lecteur->load($chemin);
        } catch (\Throwable) {
            throw new ImportInvalideException('Classeur illisible : il est peut-être endommagé ou protégé par un mot de passe.');
        }

        // La feuille « Données » du modèle si elle existe, la première sinon.
        $feuille = $classeur->getSheetByName('Données') ?? $classeur->getSheet(0);
        if ($feuille->getHighestDataRow() > self::LIGNES_MAX + 10) {
            throw new ImportInvalideException(\sprintf('Trop de lignes : %d au plus par import.', self::LIGNES_MAX));
        }

        /** @var list<list<mixed>> $grille */
        $grille = $feuille->toArray(null, false, false, false);

        return $grille;
    }

    /** @return array{0: list<list<mixed>>, 1: string} */
    private function grilleCsv(string $chemin): array
    {
        $contenu = (string) file_get_contents($chemin);
        $encodage = 'UTF-8';

        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);
        } elseif (str_starts_with($contenu, "\xFF\xFE") || str_starts_with($contenu, "\xFE\xFF")) {
            $encodage = str_starts_with($contenu, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $contenu = (string) mb_convert_encoding(substr($contenu, 2), 'UTF-8', $encodage);
        } elseif (!mb_check_encoding($contenu, 'UTF-8')) {
            // Excel français enregistre ses CSV en Windows-1252 : « é » y
            // tient sur un octet que l'UTF-8 refuse.
            $encodage = 'Windows-1252';
            $contenu = (string) mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }

        $premiereLigne = strtok($contenu, "\n") ?: '';
        $separateurs = [';' => substr_count($premiereLigne, ';'), ',' => substr_count($premiereLigne, ','), "\t" => substr_count($premiereLigne, "\t")];
        arsort($separateurs);
        $separateur = (string) array_key_first($separateurs);

        $flux = fopen('php://temp', 'r+');
        if (false === $flux) {
            throw new ImportInvalideException('Lecture du fichier impossible.');
        }
        fwrite($flux, $contenu);
        rewind($flux);

        $grille = [];
        while (false !== ($ligne = fgetcsv($flux, null, $separateur, '"', ''))) {
            $grille[] = $ligne;
            if (\count($grille) > self::LIGNES_MAX + 10) {
                fclose($flux);
                throw new ImportInvalideException(\sprintf('Trop de lignes : %d au plus par import.', self::LIGNES_MAX));
            }
        }
        fclose($flux);

        return [$grille, $encodage];
    }

    /**
     * @param list<list<mixed>>   $grille
     * @param list<ColonneImport> $colonnes
     *
     * @return array{lignes: array<int, array<string, string|float|int|null>>, colonnesIgnorees: list<string>}
     */
    private function extraire(array $grille, array $colonnes): array
    {
        $parIntitule = [];
        foreach ($colonnes as $colonne) {
            foreach ([$colonne->entete, ...$colonne->alias] as $intitule) {
                $parIntitule[$this->cleEntete($intitule)] = $colonne->cle;
            }
        }
        $obligatoires = array_filter($colonnes, static fn (ColonneImport $c) => $c->obligatoire);

        // Ligne d'en-tête : la première des dix premières qui porte toutes les
        // colonnes obligatoires (une ligne de titre peut la précéder).
        $indexEntete = null;
        $correspondance = [];
        foreach (\array_slice($grille, 0, 10, true) as $index => $ligne) {
            $trouvees = [];
            foreach ($ligne as $position => $valeur) {
                $cle = $parIntitule[$this->cleEntete((string) $valeur)] ?? null;
                if (null !== $cle && !\in_array($cle, $trouvees, true)) {
                    $trouvees[$position] = $cle;
                }
            }
            $manquantes = array_filter($obligatoires, static fn (ColonneImport $c) => !\in_array($c->cle, $trouvees, true));
            if ([] === $manquantes) {
                $indexEntete = $index;
                $correspondance = $trouvees;

                break;
            }
            if (\count($trouvees) > \count($correspondance)) {
                $correspondance = $trouvees;
            }
        }

        if (null === $indexEntete) {
            $manquantes = array_map(
                static fn (ColonneImport $c) => '« '.$c->entete.' »',
                array_filter($obligatoires, static fn (ColonneImport $c) => !\in_array($c->cle, $correspondance, true)),
            );
            throw new ImportInvalideException('Colonnes obligatoires introuvables : '.implode(', ', $manquantes).'. Partez du modèle téléchargeable.');
        }

        $colonnesIgnorees = [];
        foreach ($grille[$indexEntete] as $position => $valeur) {
            if (!isset($correspondance[$position]) && '' !== trim((string) $valeur)) {
                $colonnesIgnorees[] = trim((string) $valeur);
            }
        }

        $lignes = [];
        foreach ($grille as $index => $ligne) {
            if ($index <= $indexEntete) {
                continue;
            }
            $valeurs = [];
            foreach ($correspondance as $position => $cle) {
                $brute = $ligne[$position] ?? null;
                $valeurs[$cle] = \is_string($brute) ? trim($brute) : $brute;
            }
            if ([] === array_filter($valeurs, static fn ($v) => null !== $v && '' !== $v)) {
                continue;
            }
            // Numéro de ligne tel que l'utilisateur le voit dans Excel.
            $lignes[$index + 1] = $valeurs;
        }

        if ([] === $lignes) {
            throw new ImportInvalideException('Aucune ligne de données sous l\'en-tête.');
        }
        if (\count($lignes) > self::LIGNES_MAX) {
            throw new ImportInvalideException(\sprintf('Trop de lignes : %d au plus par import.', self::LIGNES_MAX));
        }

        return ['lignes' => $lignes, 'colonnesIgnorees' => $colonnesIgnorees];
    }

    /** En-tête comparable : sans accent ni casse, sans astérisque d'obligation ni ponctuation. */
    private function cleEntete(string $intitule): string
    {
        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', Normalisation::cle(str_replace('*', '', $intitule))));
    }

    /** Valeur texte d'une cellule : un nombre entier lu comme flottant (771234567.0) redevient « 771234567 ». */
    public static function texte(string|float|int|null $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }
        if (\is_float($valeur) && floor($valeur) === $valeur && abs($valeur) < 1e15) {
            $valeur = (string) (int) $valeur;
        }
        $texte = trim((string) $valeur);

        return '' === $texte ? null : $texte;
    }

    /** Date d'une cellule : numéro de série Excel, ou texte JJ/MM/AAAA, AAAA-MM-JJ, JJ-MM-AAAA. */
    public static function date(string|float|int|null $valeur): ?\DateTimeImmutable
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }
        if (is_numeric($valeur)) {
            $serie = (float) $valeur;
            if ($serie < 1 || $serie > 2958465) {
                throw new \InvalidArgumentException('date illisible');
            }

            return \DateTimeImmutable::createFromMutable(DateExcel::excelToDateTimeObject($serie))->setTime(0, 0);
        }

        $texte = trim($valeur);
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'd/m/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $texte);
            $erreurs = \DateTimeImmutable::getLastErrors();
            if (false !== $date && (false === $erreurs || (0 === $erreurs['warning_count'] && 0 === $erreurs['error_count']))) {
                return $date;
            }
        }

        throw new \InvalidArgumentException('date illisible');
    }
}
