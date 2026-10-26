<?php

declare(strict_types=1);

namespace App\Import;

use App\Classeur\Normalisation;
use App\Entity\Activite;
use App\Entity\SousActivite;
use App\Entity\Structure;
use App\Enum\NatureEngagement;
use App\Enum\Sexe;
use App\Export\LibellesExport;
use App\Repository\ActiviteRepository;
use App\Repository\StructureRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Correspondances communes aux imports : structure par sigle, opération par
 * code PTBA, sexe, date, nombre, courriel — avec, pour chaque refus, un
 * message qui dit à l'utilisateur quoi corriger dans sa ligne.
 *
 * Les index sont gardés le temps d'une requête et vidés ensuite
 * (ResetInterface) : conservés d'une requête à l'autre, ils tiendraient des
 * entités que le gestionnaire d'entités ne connaît plus.
 */
final class ReferentielsImport implements ResetInterface
{
    /** @var array<string, Structure>|null */
    private ?array $structures = null;

    /** @var array<string, Activite>|null */
    private ?array $activites = null;

    /** Séparateur entre le code de l'activité et le libellé de la ligne, dans la liste du modèle. */
    public const SEPARATEUR_LIGNE = ' — ';

    /** Excel refuse une valeur de liste de plus de 255 caractères. */
    private const LONGUEUR_LISTE = 240;

    public function __construct(
        private readonly StructureRepository $structureRepository,
        private readonly ActiviteRepository $activiteRepository,
    ) {
    }

    public function reset(): void
    {
        $this->structures = null;
        $this->activites = null;
    }

    /**
     * Lignes budgétaires du PTBA, pour la liste déroulante des imports
     * d'engagements : chaque ligne d'activité (« 3.1.2 — Ressources
     * numériques… »), et l'activité elle-même quand elle n'en a aucune — là
     * où se portent dotations et engagements.
     *
     * @return list<string>
     */
    public function lignesBudgetaires(): array
    {
        $codes = array_keys($this->indexActivites());
        natsort($codes);
        $valeurs = [];
        foreach ($codes as $code) {
            $activite = $this->indexActivites()[$code];
            $lignes = $activite->getSousActivitesActives();
            if (0 === $lignes->count()) {
                $valeurs[] = mb_substr($activite->getCode().self::SEPARATEUR_LIGNE.$activite->getLibelle(), 0, self::LONGUEUR_LISTE);
                continue;
            }
            foreach ($lignes as $ligne) {
                $valeurs[] = mb_substr($activite->getCode().self::SEPARATEUR_LIGNE.$ligne->getLibelle(), 0, self::LONGUEUR_LISTE);
            }
        }

        return $valeurs;
    }

    /**
     * La cible budgétaire d'une valeur de la liste : « code — libellé de la
     * ligne », ou le code seul d'une activité sans ligne. Le libellé peut
     * être tronqué (liste Excel limitée à 255 caractères) : il suffit qu'il
     * désigne une seule ligne de l'activité.
     *
     * @param list<string> $erreurs
     */
    public function cibleBudgetaire(?string $valeur, array &$erreurs): Activite|SousActivite|null
    {
        if (null === $valeur) {
            return null;
        }
        $morceaux = preg_split('/\s+[—–-]\s+/u', $valeur, 2) ?: [$valeur];
        $code = rtrim(trim($morceaux[0]), '.');
        $libelle = isset($morceaux[1]) ? Normalisation::cle($morceaux[1]) : '';

        $activite = $this->indexActivites()[$code] ?? null;
        if (null === $activite) {
            $erreurs[] = \sprintf('Ligne budgétaire « %s » inconnue : choisissez une valeur de la liste (code de l\'activité, puis libellé de la ligne).', $valeur);

            return null;
        }
        $lignes = $activite->getSousActivitesActives();
        if (0 === $lignes->count()) {
            return $activite;
        }
        if ('' === $libelle) {
            $erreurs[] = \sprintf('L\'activité %s a des lignes budgétaires : précisez laquelle (choisissez-la dans la liste).', $code);

            return null;
        }

        $trouvees = $lignes->filter(static fn (SousActivite $l) => str_starts_with(Normalisation::cle($l->getLibelle()), $libelle))->getValues();
        if (1 !== \count($trouvees)) {
            $erreurs[] = 0 === \count($trouvees)
                ? \sprintf('Aucune ligne de l\'activité %s ne correspond à « %s ».', $code, $morceaux[1] ?? '')
                : \sprintf('Plusieurs lignes de l\'activité %s correspondent à « %s » : précisez le libellé.', $code, $morceaux[1] ?? '');

            return null;
        }

        return $trouvees[0];
    }

    /** @return list<string> libellés des natures d'engagement, pour la liste du modèle */
    public static function naturesEngagement(): array
    {
        return array_map(static fn (NatureEngagement $n) => LibellesExport::nature($n->value), NatureEngagement::cases());
    }

    /**
     * Nature d'engagement depuis son libellé (« Fournitures ») ou son code
     * (« fournitures »).
     *
     * @param list<string> $erreurs
     */
    public static function natureEngagement(?string $valeur, array &$erreurs): ?NatureEngagement
    {
        if (null === $valeur) {
            return null;
        }
        $cle = Normalisation::cle($valeur);
        foreach (NatureEngagement::cases() as $nature) {
            if ($cle === Normalisation::cle($nature->value) || $cle === Normalisation::cle(LibellesExport::nature($nature->value))) {
                return $nature;
            }
        }
        $erreurs[] = \sprintf('Nature « %s » inconnue : choisissez une valeur de la liste.', $valeur);

        return null;
    }

    /**
     * Montant en FCFA entiers (règle du projet : jamais de décimales) —
     * nombre Excel, ou texte avec espaces ou points comme séparateurs de
     * milliers (« 1 250 000 », « 1.250.000 »).
     *
     * @param list<string> $erreurs
     */
    public static function montant(string|float|int|null $valeur, string $champ, array &$erreurs): ?int
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }
        if (\is_int($valeur) || \is_float($valeur)) {
            if (floor((float) $valeur) !== (float) $valeur || $valeur <= 0 || $valeur > 1e15) {
                $erreurs[] = \sprintf('%s « %s » invalide : montant entier en FCFA, positif.', $champ, (string) $valeur);

                return null;
            }

            return (int) $valeur;
        }
        $texte = preg_replace('/[\s\x{00A0}\x{202F}.]|FCFA|F CFA/iu', '', $valeur) ?? '';
        $texte = (string) preg_replace('/,0+$/', '', $texte);
        if (!preg_match('/^\d{1,15}$/', $texte) || 0 === (int) $texte) {
            $erreurs[] = \sprintf('%s « %s » invalide : montant entier en FCFA, positif.', $champ, $valeur);

            return null;
        }

        return (int) $texte;
    }

    /** @return list<string> sigles des structures, pour la liste déroulante du modèle */
    public function sigles(): array
    {
        return array_values(array_map(static fn (Structure $s) => $s->getSigle(), $this->indexStructures()));
    }

    /** @return list<string> codes des opérations du PTBA, pour la liste déroulante du modèle */
    public function codesOperations(): array
    {
        $codes = array_keys($this->indexActivites());
        natsort($codes);

        return array_values(array_map('strval', $codes));
    }

    /** @param list<string> $erreurs */
    public function structure(?string $sigle, array &$erreurs): ?Structure
    {
        if (null === $sigle) {
            return null;
        }
        $structure = $this->indexStructures()[Normalisation::cle($sigle)] ?? null;
        if (null === $structure) {
            $erreurs[] = \sprintf('Structure « %s » inconnue : utilisez un sigle de la liste.', $sigle);
        }

        return $structure;
    }

    /** @param list<string> $erreurs */
    public function activite(?string $code, array &$erreurs): ?Activite
    {
        if (null === $code) {
            return null;
        }
        $activite = $this->indexActivites()[rtrim($code, '.')] ?? null;
        if (null === $activite) {
            $erreurs[] = \sprintf('Opération « %s » inconnue : indiquez un code d\'activité du PTBA (ex. 1.2.3).', $code);
        }

        return $activite;
    }

    /** @param list<string> $erreurs */
    public static function sexe(?string $valeur, array &$erreurs): ?Sexe
    {
        if (null === $valeur) {
            return null;
        }

        $sexe = match (Normalisation::cle($valeur)) {
            'F', 'FEMININ', 'FEMME', 'FEMMES' => Sexe::FEMININ,
            'M', 'H', 'MASCULIN', 'HOMME', 'HOMMES' => Sexe::MASCULIN,
            default => null,
        };
        if (null === $sexe) {
            $erreurs[] = \sprintf('Sexe « %s » non reconnu : F ou M.', $valeur);
        }

        return $sexe;
    }

    /** @param list<string> $erreurs */
    public static function date(string|float|int|null $valeur, string $champ, array &$erreurs): ?\DateTimeImmutable
    {
        try {
            return LecteurFichierImport::date($valeur);
        } catch (\InvalidArgumentException) {
            $erreurs[] = \sprintf('%s « %s » illisible : JJ/MM/AAAA.', $champ, (string) $valeur);

            return null;
        }
    }

    /** @param list<string> $erreurs */
    public static function entier(string|float|int|null $valeur, string $champ, array &$erreurs, int $min = 0, int $max = PHP_INT_MAX): ?int
    {
        $texte = LecteurFichierImport::texte($valeur);
        if (null === $texte) {
            return null;
        }
        if (!preg_match('/^\d+$/', $texte) || (int) $texte < $min || (int) $texte > $max) {
            $erreurs[] = \sprintf('%s « %s » invalide : nombre entier attendu.', $champ, $texte);

            return null;
        }

        return (int) $texte;
    }

    /** @param list<string> $erreurs */
    public static function courriel(?string $valeur, array &$erreurs): ?string
    {
        if (null !== $valeur && false === filter_var($valeur, \FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = \sprintf('Adresse e-mail « %s » invalide.', $valeur);

            return null;
        }

        return $valeur;
    }

    /** @param list<string> $erreurs */
    public static function ouiNon(?string $valeur, string $champ, array &$erreurs): ?bool
    {
        if (null === $valeur) {
            return null;
        }

        return match (Normalisation::cle($valeur)) {
            'OUI', 'O', 'X', '1', 'VRAI' => true,
            'NON', 'N', '0', 'FAUX' => false,
            default => (function () use ($valeur, $champ, &$erreurs) {
                $erreurs[] = \sprintf('%s « %s » : Oui ou Non attendu.', $champ, $valeur);

                return null;
            })(),
        };
    }

    /** @return array<string, Structure> */
    private function indexStructures(): array
    {
        if (null === $this->structures) {
            $this->structures = [];
            foreach ($this->structureRepository->listerNonSupprimees() as $structure) {
                $this->structures[Normalisation::cle($structure->getSigle())] = $structure;
            }
        }

        return $this->structures;
    }

    /** @return array<string, Activite> */
    private function indexActivites(): array
    {
        if (null === $this->activites) {
            $this->activites = [];
            foreach ($this->activiteRepository->findBy(['supprimeLe' => null]) as $activite) {
                $this->activites[$activite->getCode()] = $activite;
            }
        }

        return $this->activites;
    }
}
