<?php

declare(strict_types=1);

namespace App\PointsFocaux;

use App\Classeur\LecteurClasseur;
use App\Classeur\Normalisation;

/**
 * Lit le classeur des contacts des points focaux du PHASAOC.
 *
 * Forme du classeur : des sections par composante, chacune précédée de son
 * titre et d'une ligne d'en-tête, puis une ligne par activité du PTBA avec
 * la ou les personnes qui en répondent. Plusieurs en-têtes se succèdent donc
 * dans la même feuille — le lecteur les reconnaît et les saute, au lieu de
 * s'arrêter au premier.
 *
 * Une cellule peut porter plusieurs personnes, une par ligne, avec les
 * téléphones et les courriels alignés dans le même ordre. C'est fragile par
 * nature : si les trois colonnes n'ont pas le même nombre de lignes, le
 * lecteur rattache ce qu'il peut et laisse le reste à null plutôt que de
 * décaler les coordonnées d'une personne à l'autre.
 */
final class LecteurContacts
{
    private const ENTETE_NOM = 'PRENOMS & NOM';
    private const ENTETE_STRUCTURE = 'STRUCTURE / DIRECTION';
    private const ENTETE_TELEPHONE = 'TELEPHONE';
    private const ENTETE_EMAIL = 'E-MAIL';
    private const ENTETE_CODE = 'ACTIVITES RETENUES DANS LE PTBA 2026';

    public function __construct(private readonly LecteurClasseur $classeur)
    {
    }

    /**
     * @return list<LigneContact>
     *
     * @throws \RuntimeException si aucune ligne d'en-tête n'est reconnue
     */
    public function lire(string $chemin, ?string $feuille = null): array
    {
        $lignes = $this->classeur->feuille($chemin, $feuille);

        $colonnes = null;
        $contacts = [];

        foreach ($lignes as $numero => $cellules) {
            $entete = $this->repererColonnes($cellules);
            if (null !== $entete) {
                $colonnes = $entete;

                continue;
            }
            if (null === $colonnes) {
                continue;
            }

            $code = $this->code($cellules[$colonnes['code']] ?? '');
            if (null === $code) {
                continue;
            }

            $structure = trim($cellules[$colonnes['structure']] ?? '');
            $libelle = trim($cellules[$colonnes['libelle']] ?? '');
            if ('' === $structure) {
                continue;
            }

            $noms = $this->decouper($cellules[$colonnes['nom']] ?? '');
            $telephones = $this->decouper($cellules[$colonnes['telephone']] ?? '');
            $emails = $this->decouper($cellules[$colonnes['email']] ?? '');

            foreach ($noms as $index => $nom) {
                [$nomComplet, $fonction] = $this->separerNomEtFonction($nom);
                if (!$this->ressembleAUnNom($nomComplet)) {
                    continue;
                }

                $contacts[] = new LigneContact(
                    code: $code,
                    libelleActivite: $libelle,
                    nomComplet: $nomComplet,
                    fonction: $fonction,
                    telephone: $telephones[$index] ?? null,
                    email: $emails[$index] ?? null,
                    structure: $structure,
                    ligne: $numero,
                );
            }
        }

        if (null === $colonnes) {
            throw new \RuntimeException('En-tête introuvable : aucune ligne ne porte « Prénoms & Nom » et « Structure / Direction ».');
        }

        return $contacts;
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

        if (!isset($parIntitule[self::ENTETE_NOM], $parIntitule[self::ENTETE_STRUCTURE])) {
            return null;
        }

        // La colonne des codes porte l'intitulé de la section ; celle des
        // libellés n'a pas d'en-tête propre, elle suit immédiatement.
        $code = $parIntitule[self::ENTETE_CODE] ?? 'A';

        return [
            'code' => $code,
            'libelle' => $this->colonneSuivante($code),
            'nom' => $parIntitule[self::ENTETE_NOM],
            'structure' => $parIntitule[self::ENTETE_STRUCTURE],
            'telephone' => $parIntitule[self::ENTETE_TELEPHONE] ?? '',
            'email' => $parIntitule[self::ENTETE_EMAIL] ?? '',
        ];
    }

    /** Colonne immédiatement à droite, limitée aux colonnes simples (A → B) : le classeur n'en compte qu'une dizaine. */
    private function colonneSuivante(string $colonne): string
    {
        return 1 === \strlen($colonne) && 'Z' !== $colonne ? \chr(\ord($colonne) + 1) : $colonne;
    }

    /** @return list<string> */
    private function decouper(string $valeur): array
    {
        $morceaux = preg_split('/\R+/u', trim($valeur)) ?: [];

        return array_values(array_filter(array_map('trim', $morceaux), static fn (string $m) => '' !== $m));
    }

    /**
     * « Abdoulahi Sall (Chef de projet SIME) » → nom et fonction séparés.
     *
     * @return array{0: string, 1: ?string}
     */
    private function separerNomEtFonction(string $valeur): array
    {
        if (1 === preg_match('/^(.*?)\s*\((.+)\)\s*$/u', trim($valeur), $morceaux)) {
            return [trim($morceaux[1]), trim($morceaux[2])];
        }

        return [trim($valeur), null];
    }

    /**
     * Écarte les cellules qui ne nomment personne : le classeur y met parfois
     * le service lui-même (« UGP ») faute de personne désignée. Un nom tient
     * en au moins deux mots, ou un seul d'au moins quatre lettres.
     */
    private function ressembleAUnNom(string $valeur): bool
    {
        if ('' === $valeur) {
            return false;
        }

        return str_contains($valeur, ' ') || mb_strlen($valeur) >= 4;
    }

    private function code(string $valeur): ?string
    {
        $code = rtrim(trim($valeur), '.');

        return 1 === preg_match('/^\d+(\.\d+)*$/', $code) ? $code : null;
    }
}
