<?php

declare(strict_types=1);

namespace App\Classeur;

/**
 * Lecture bas niveau d'un classeur .xlsx, sans bibliothèque tierce : un
 * .xlsx est une archive ZIP de fichiers XML, et PHP sait ouvrir les deux.
 * Ajouter PhpSpreadsheet pour lire trois colonnes se paierait à chaque mise
 * à jour du projet.
 *
 * Ce lecteur ne connaît aucun métier : il rend les feuilles telles quelles,
 * sous la forme numéro de ligne => (lettre de colonne => texte). Les
 * lecteurs métier (personnel, PTBA, points focaux) s'appuient dessus et
 * portent seuls les règles de forme de leur classeur.
 *
 * Les valeurs sont rendues en texte brut, y compris les nombres : un montant
 * FCFA ne doit jamais transiter par un flottant (§ montants entiers), et
 * c'est à l'appelant de décider comment interpréter « 46023 » — une date en
 * numérotation Excel ici, un montant là.
 */
final class LecteurClasseur
{
    /**
     * @return array<string, array<int, array<string, string>>> nom de feuille => lignes
     *
     * @throws \RuntimeException si le fichier n'est pas un classeur lisible
     */
    public function lire(string $chemin): array
    {
        if (!is_file($chemin)) {
            throw new \RuntimeException(\sprintf('Fichier introuvable : %s', $chemin));
        }

        $archive = new \ZipArchive();
        if (true !== $archive->open($chemin)) {
            throw new \RuntimeException(\sprintf("« %s » n'est pas un classeur .xlsx lisible.", basename($chemin)));
        }

        try {
            $chaines = $this->chainesPartagees($archive);
            $feuilles = [];
            foreach ($this->emplacementsDesFeuilles($archive) as $nom => $emplacement) {
                $xml = $archive->getFromName($emplacement);
                if (false !== $xml) {
                    $feuilles[$nom] = $this->extraireLignes($xml, $chaines);
                }
            }

            if ([] === $feuilles) {
                throw new \RuntimeException('Le classeur ne contient aucune feuille exploitable.');
            }

            return $feuilles;
        } finally {
            $archive->close();
        }
    }

    /**
     * Une feuille nommée, ou la première du classeur à défaut.
     *
     * @return array<int, array<string, string>>
     *
     * @throws \RuntimeException si la feuille demandée n'existe pas
     */
    public function feuille(string $chemin, ?string $nom = null): array
    {
        $feuilles = $this->lire($chemin);
        if (null === $nom) {
            return reset($feuilles);
        }

        // Comparaison insensible à la casse et aux accents : « PTBA-2026_RÉADAPTÉ »
        // se tape rarement à l'identique en ligne de commande.
        foreach ($feuilles as $titre => $lignes) {
            if (Normalisation::cle($titre) === Normalisation::cle($nom)) {
                return $lignes;
            }
        }

        throw new \RuntimeException(\sprintf('Feuille « %s » introuvable. Feuilles disponibles : %s.', $nom, implode(', ', array_keys($feuilles))));
    }

    /**
     * Les noms de feuilles vivent dans workbook.xml, leur emplacement réel
     * dans les relations : l'ordre des fichiers sheetN.xml ne suit pas
     * toujours l'ordre des onglets.
     *
     * @return array<string, string> nom de feuille => chemin dans l'archive
     */
    private function emplacementsDesFeuilles(\ZipArchive $archive): array
    {
        $workbook = $archive->getFromName('xl/workbook.xml');
        $relations = $archive->getFromName('xl/_rels/workbook.xml.rels');
        if (false === $workbook) {
            return [];
        }

        $cibles = [];
        if (false !== $relations) {
            preg_match_all('#Id="(rId\d+)"[^>]*Target="([^"]+)"#', $relations, $correspondances, \PREG_SET_ORDER);
            foreach ($correspondances as $relation) {
                $cibles[$relation[1]] = $relation[2];
            }
        }

        // La relation est facultative dans cette lecture : à défaut, on
        // retombe sur la convention de nommage par position. Les classeurs
        // produits par Excel la portent toujours, mais un classeur minimal
        // (celui des tests, par exemple) reste lisible.
        $emplacements = [];
        preg_match_all('#<sheet[^>]*?name="([^"]+)"[^>]*?(?:r:id="(rId\d+)")?[^>]*/?>#', $workbook, $feuilles, \PREG_SET_ORDER);
        foreach ($feuilles as $index => $feuille) {
            $relation = $feuille[2] ?? '';
            $cible = ('' !== $relation ? $cibles[$relation] ?? null : null) ?? \sprintf('worksheets/sheet%d.xml', $index + 1);
            $emplacements[$this->decoder($feuille[1])] = 'xl/'.ltrim(str_replace('xl/', '', $cible), '/');
        }

        return $emplacements;
    }

    /** @return list<string> */
    private function chainesPartagees(\ZipArchive $archive): array
    {
        $xml = $archive->getFromName('xl/sharedStrings.xml');
        if (false === $xml) {
            return [];
        }

        $chaines = [];
        preg_match_all('#<si>(.*?)</si>#s', $xml, $correspondances);
        foreach ($correspondances[1] as $si) {
            // Une même chaîne peut être découpée en plusieurs <t> quand une
            // partie du texte est mise en forme : on les recolle.
            preg_match_all('#<t[^>]*>(.*?)</t>#s', $si, $morceaux);
            $chaines[] = $this->decoder(implode('', $morceaux[1]));
        }

        return $chaines;
    }

    /**
     * @param list<string> $chaines
     *
     * @return array<int, array<string, string>>
     */
    private function extraireLignes(string $feuille, array $chaines): array
    {
        $lignes = [];
        preg_match_all('#<row[^>]*r="(\d+)"[^>]*>(.*?)</row>#s', $feuille, $correspondances, \PREG_SET_ORDER);

        foreach ($correspondances as $ligne) {
            $cellules = [];
            preg_match_all('#<c r="([A-Z]+)\d+"([^>]*?)(?:/>|>(.*?)</c>)#s', $ligne[2], $brutes, \PREG_SET_ORDER);
            foreach ($brutes as $cellule) {
                $cellules[$cellule[1]] = trim($this->valeurCellule($cellule[2], $cellule[3] ?? '', $chaines));
            }
            $lignes[(int) $ligne[1]] = $cellules;
        }

        ksort($lignes);

        return $lignes;
    }

    /** @param list<string> $chaines */
    private function valeurCellule(string $attributs, string $contenu, array $chaines): string
    {
        if (str_contains($attributs, 't="inlineStr"')) {
            preg_match('#<t[^>]*>(.*?)</t>#s', $contenu, $morceau);

            return $this->decoder($morceau[1] ?? '');
        }

        preg_match('#<v>(.*?)</v>#s', $contenu, $valeur);
        $brute = $valeur[1] ?? '';

        if (str_contains($attributs, 't="s"') && '' !== $brute) {
            return $chaines[(int) $brute] ?? '';
        }

        return $this->decoder($brute);
    }

    private function decoder(string $valeur): string
    {
        return html_entity_decode($valeur, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
