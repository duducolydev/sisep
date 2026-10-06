<?php

declare(strict_types=1);

namespace App\Ptba;

use App\Classeur\LecteurClasseur;
use App\Classeur\Normalisation;

/**
 * Lit le PTBA annuel du PHASAOC (« PTBA-2026_Révisé-soumis-DG… .xlsx »).
 *
 * Le classeur porte deux onglets : la version soumise et la version
 * réadaptée après arbitrage. C'est la seconde qui fait foi — elle intègre le
 * reliquat de la loi de finances rectificative — d'où le choix par défaut,
 * surchargeable en ligne de commande le jour où un classeur nomme ses
 * onglets autrement.
 *
 * Les commentaires de révision ne figurent que dans l'onglet d'origine : ils
 * y sont relus et rapprochés par code, pour que l'import ne perde pas la
 * justification des écarts (« Ajout correspondant au gap entre… »).
 */
final class LecteurPtba
{
    public const FEUILLE_PAR_DEFAUT = 'PTBA-2026_RÉADAPTÉ';
    public const FEUILLE_COMMENTAIRES = 'PTBA-2026';

    /** Intitulés d'en-tête repérant la ligne de titres, une fois normalisés. */
    private const ENTETE_CODE = 'N°';
    private const ENTETE_INTITULE = 'INTITULE DU PROJET';

    public function __construct(private readonly LecteurClasseur $classeur)
    {
    }

    /**
     * @return list<LignePtba>
     *
     * @throws \RuntimeException si l'en-tête est introuvable
     */
    public function lire(string $chemin, ?string $feuille = null): array
    {
        $lignes = $this->classeur->feuille($chemin, $feuille ?? self::FEUILLE_PAR_DEFAUT);
        $commentaires = $this->commentaires($chemin);

        $colonnes = null;
        $resultat = [];

        foreach ($lignes as $numero => $cellules) {
            if (null === $colonnes) {
                $colonnes = $this->repererColonnes($cellules);

                continue;
            }

            $code = $this->code($cellules[$colonnes['code']] ?? '');
            $libelle = trim($cellules[$colonnes['libelle']] ?? '');
            if (null === $code || '' === $libelle) {
                continue;
            }

            $resultat[] = new LignePtba(
                code: $code,
                libelle: $libelle,
                niveau: substr_count($code, '.') + 1,
                montant: $this->montant($cellules[$colonnes['montant']] ?? '') ?? 0,
                trimestre1: $this->montant($cellules[$colonnes['t1']] ?? ''),
                trimestre2: $this->montant($cellules[$colonnes['t2']] ?? ''),
                trimestre3: $this->montant($cellules[$colonnes['t3']] ?? ''),
                trimestre4: $this->montant($cellules[$colonnes['t4']] ?? ''),
                montantValideCopil: $this->montant($cellules[$colonnes['copil']] ?? ''),
                commentaire: $commentaires[$code] ?? null,
                ligne: $numero,
            );
        }

        if (null === $colonnes) {
            throw new \RuntimeException('En-tête introuvable : aucune ligne ne porte à la fois « N° » et « Intitulé du projet ».');
        }

        return $resultat;
    }

    /**
     * Commentaires de révision de l'onglet d'origine, indexés par code.
     *
     * Leur absence n'est pas une erreur : un classeur d'une autre année peut
     * ne pas porter cet onglet, et l'import doit continuer sans eux.
     *
     * @return array<string, string>
     */
    private function commentaires(string $chemin): array
    {
        try {
            $lignes = $this->classeur->feuille($chemin, self::FEUILLE_COMMENTAIRES);
        } catch (\RuntimeException) {
            return [];
        }

        $colonnes = null;
        $commentaires = [];
        foreach ($lignes as $cellules) {
            if (null === $colonnes) {
                $colonnes = $this->repererColonnes($cellules);

                continue;
            }

            $code = $this->code($cellules[$colonnes['code']] ?? '');
            $commentaire = trim($cellules[$colonnes['commentaire']] ?? '');
            if (null !== $code && '' !== $commentaire) {
                $commentaires[$code] = $commentaire;
            }
        }

        return $commentaires;
    }

    /**
     * Colonnes repérées par leur intitulé et non par leur position : le
     * classeur gagne une colonne d'une version à l'autre (« Montant ajouté »
     * n'existe que dans l'onglet d'origine), et une position figée casserait
     * l'import l'année suivante.
     *
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

        if (!isset($parIntitule[self::ENTETE_CODE], $parIntitule[self::ENTETE_INTITULE])) {
            return null;
        }

        // Le montant notifié est le budget révisé le plus récent de la
        // feuille lue : « révisé mars 2026 » dans les deux onglets.
        $montant = $this->premiereColonne($parIntitule, ['REVISE', 'BUDGET TOTAL']);
        $copil = $this->premiereColonne($parIntitule, ['VALIDE PAR LE COPIL', 'AMENDE']);

        return [
            'code' => $parIntitule[self::ENTETE_CODE],
            'libelle' => $parIntitule[self::ENTETE_INTITULE],
            't1' => $this->premiereColonne($parIntitule, ['TRIM 1']) ?? '',
            't2' => $this->premiereColonne($parIntitule, ['TRIM 2']) ?? '',
            't3' => $this->premiereColonne($parIntitule, ['TRIM 3']) ?? '',
            't4' => $this->premiereColonne($parIntitule, ['TRIM 4']) ?? '',
            'montant' => $montant ?? '',
            // Jamais la même colonne que le notifié : quand la feuille n'en
            // distingue qu'un, le COPIL reste vide plutôt que recopié.
            'copil' => $copil !== $montant ? ($copil ?? '') : '',
            'commentaire' => $this->premiereColonne($parIntitule, ['COMMENTAIRE']) ?? '',
        ];
    }

    /**
     * @param array<string, string> $parIntitule
     * @param list<string>          $fragments
     */
    private function premiereColonne(array $parIntitule, array $fragments): ?string
    {
        foreach ($fragments as $fragment) {
            foreach ($parIntitule as $intitule => $colonne) {
                if (str_contains($intitule, $fragment)) {
                    return $colonne;
                }
            }
        }

        return null;
    }

    /** Code hiérarchique normalisé, sans point final ; null si la cellule n'en porte pas un. */
    private function code(string $valeur): ?string
    {
        $code = rtrim(trim($valeur), '.');

        return 1 === preg_match('/^\d+(\.\d+)*$/', $code) ? $code : null;
    }

    /**
     * Montant FCFA entier. Le classeur porte des flottants (ses formules
     * divisent par trois), arrondis ici une fois pour toutes : au-delà, plus
     * aucun montant n'a le droit d'être un flottant (§ montants entiers).
     */
    private function montant(string $valeur): ?int
    {
        $valeur = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim($valeur));
        if ('' === $valeur || !is_numeric($valeur)) {
            return null;
        }

        return (int) round((float) $valeur);
    }
}
