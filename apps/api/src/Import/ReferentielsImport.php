<?php

declare(strict_types=1);

namespace App\Import;

use App\Classeur\Normalisation;
use App\Entity\Activite;
use App\Entity\Structure;
use App\Enum\Sexe;
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
