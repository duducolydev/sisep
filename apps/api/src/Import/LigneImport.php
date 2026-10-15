<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Verdict sur une ligne du fichier : ce qu'elle deviendra (création, mise à
 * jour), pourquoi elle est écartée (doublon, déjà présente, erreur), et de
 * quoi l'appliquer si elle est retenue.
 */
final class LigneImport
{
    public const CREATION = 'creation';
    public const MISE_A_JOUR = 'mise_a_jour';
    public const EXISTANTE = 'existante';
    public const DOUBLON = 'doublon';
    public const ERREUR = 'erreur';

    /**
     * @param list<string>  $messages
     * @param \Closure|null $application écriture à exécuter si l'import est confirmé
     */
    public function __construct(
        public readonly int $ligne,
        public readonly string $libelle,
        public readonly string $statut,
        public readonly array $messages = [],
        public readonly ?\Closure $application = null,
    ) {
    }

    /** @param list<string> $messages */
    public static function erreur(int $ligne, string $libelle, array $messages): self
    {
        return new self($ligne, $libelle, self::ERREUR, $messages);
    }

    public function estRetenue(): bool
    {
        return \in_array($this->statut, [self::CREATION, self::MISE_A_JOUR], true);
    }

    /** @return array{ligne: int, libelle: string, statut: string, messages: list<string>} */
    public function versTableau(): array
    {
        return ['ligne' => $this->ligne, 'libelle' => $this->libelle, 'statut' => $this->statut, 'messages' => $this->messages];
    }
}
