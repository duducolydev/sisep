<?php

declare(strict_types=1);

namespace App\Rh;

use App\Classeur\LecteurClasseur;
use App\Classeur\Normalisation;
use App\Enum\Sexe;

/**
 * Lit l'état du personnel technique de l'UGP (« Situation du personnel en
 * 2026.xlsx »). La mécanique ZIP/XML est dans LecteurClasseur ; ici ne
 * vivent que les règles de forme de ce classeur-là.
 *
 * Le classeur est un état de paie mensuel : il change de nom de fichier et
 * de contenu tous les mois, mais garde sa forme — un en-tête
 * PRENOMS / NOM / FONCTION / DATE DEBUT CONTRAT / DATE FIN CONTRAT / SEXE,
 * puis des blocs d'agents précédés du titre de leur opération et suivis
 * d'une ligne « SOUS TOTAL ». Les colonnes sont donc repérées par leur
 * intitulé et non par leur position : déplacer une colonne ne casse rien.
 *
 * Aucune colonne salariale n'est lue, même quand le classeur en contient :
 * la réunion du 22/09/2026 les a écartées comme sensibles (§ 14).
 */
final class LecteurClasseurPersonnel
{
    public function __construct(private readonly LecteurClasseur $classeur)
    {
    }

    /** Intitulés attendus en en-tête, une fois normalisés (majuscules, sans accent). */
    private const COLONNES = [
        'prenom' => 'PRENOMS',
        'nom' => 'NOM',
        'fonction' => 'FONCTION',
        'dateDebut' => 'DATE DEBUT CONTRAT',
        'dateFin' => 'DATE FIN CONTRAT',
        'sexe' => 'SEXE',
    ];

    /**
     * Les lignes de total ne sont pas des agents. Comparé après
     * normalisation, donc insensible à la casse et aux accents.
     */
    private const PREFIXES_IGNORES = ['SOUS TOTAL', 'TOTAL', 'N°', 'NO'];

    /**
     * @return list<LignePersonnel>
     *
     * @throws \RuntimeException si le fichier n'est pas un classeur lisible ou si l'en-tête est introuvable
     */
    public function lire(string $chemin): array
    {
        $lignesBrutes = $this->classeur->feuille($chemin);

        $colonnes = null;
        $operation = null;
        $personnes = [];

        foreach ($lignesBrutes as $numero => $cellules) {
            if (null === $colonnes) {
                $colonnes = $this->repererColonnes($cellules);

                continue;
            }

            $titre = $this->titreDeSection($cellules, $colonnes);
            if (null !== $titre) {
                $operation = $titre;

                continue;
            }

            $prenom = trim($cellules[$colonnes['prenom']] ?? '');
            $nom = trim($cellules[$colonnes['nom']] ?? '');
            if ('' === $prenom || '' === $nom) {
                continue;
            }

            $personnes[] = new LignePersonnel(
                prenom: $prenom,
                nom: $nom,
                fonction: trim($cellules[$colonnes['fonction']] ?? ''),
                sexe: Sexe::tryFrom(strtoupper(trim($cellules[$colonnes['sexe']] ?? ''))),
                dateDebut: $this->date($cellules[$colonnes['dateDebut']] ?? ''),
                dateFin: $this->date($cellules[$colonnes['dateFin']] ?? ''),
                operation: $operation,
                ligne: $numero,
            );
        }

        if (null === $colonnes) {
            throw new \RuntimeException('En-tête introuvable : aucune ligne ne porte à la fois « PRENOMS » et « NOM ».');
        }

        return $personnes;
    }

    /**
     * Titre de section : une ligne sans identité, portant un libellé dans sa
     * première cellule remplie. On ne vérifie pas qu'elle est seule sur sa
     * ligne — le classeur traîne des cellules parasites ailleurs (un « M »
     * oublié dans la colonne SEXE d'un titre de section), et une section
     * manquée rattacherait tout un bloc d'agents à la mauvaise opération.
     *
     * @param array<string, string> $cellules
     * @param array<string, string> $colonnes
     */
    private function titreDeSection(array $cellules, array $colonnes): ?string
    {
        if ('' !== trim($cellules[$colonnes['prenom']] ?? '') || '' !== trim($cellules[$colonnes['nom']] ?? '')) {
            return null;
        }

        $remplies = array_filter($cellules, static fn (string $v) => '' !== trim($v));
        if ([] === $remplies) {
            return null;
        }

        // Les cellules arrivent dans l'ordre du document : la première
        // remplie est la plus à gauche.
        $valeur = trim((string) reset($remplies));
        if (is_numeric($valeur)) {
            return null;
        }

        $normalise = Normalisation::cle($valeur);
        foreach (self::PREFIXES_IGNORES as $prefixe) {
            if (str_starts_with($normalise, $prefixe)) {
                return null;
            }
        }

        return $valeur;
    }

    /**
     * @param array<string, string> $cellules
     *
     * @return array<string, string>|null champ => lettre de colonne
     */
    private function repererColonnes(array $cellules): ?array
    {
        $parIntitule = [];
        foreach ($cellules as $colonne => $valeur) {
            $parIntitule[Normalisation::cle($valeur)] = $colonne;
        }

        // L'en-tête se reconnaît à ces deux colonnes : les titres de section
        // et les lignes de total n'en portent aucune.
        if (!isset($parIntitule[self::COLONNES['prenom']], $parIntitule[self::COLONNES['nom']])) {
            return null;
        }

        $colonnes = [];
        foreach (self::COLONNES as $champ => $intitule) {
            $colonnes[$champ] = $parIntitule[$intitule] ?? '';
        }

        return $colonnes;
    }

    /**
     * Accepte les deux formes qu'Excel produit : un numéro de série (jours
     * depuis le 30/12/1899, la base de la numérotation des dates d'Excel) ou
     * une chaîne jj/mm/aaaa saisie à la main.
     */
    private function date(string $valeur): ?\DateTimeImmutable
    {
        $valeur = trim($valeur);
        if ('' === $valeur) {
            return null;
        }

        if (ctype_digit($valeur)) {
            return (new \DateTimeImmutable('1899-12-30'))->modify(\sprintf('+%d days', (int) $valeur));
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $valeur);
            if (false !== $date) {
                return $date;
            }
        }

        return null;
    }
}
