<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Montant en FCFA entiers. Le franc CFA n'a pas de subdivision utilisée :
 * cette classe interdit toute manipulation en virgule flottante (§ 3.3 du brief).
 */
final class Montant
{
    private readonly int $fcfa;

    private function __construct(int $valeurEnFcfa)
    {
        if ($valeurEnFcfa < 0) {
            throw new \InvalidArgumentException('Un montant ne peut pas être négatif.');
        }

        $this->fcfa = $valeurEnFcfa;
    }

    public static function fromFcfa(int $valeurEnFcfa): self
    {
        return new self($valeurEnFcfa);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function versFcfa(): int
    {
        return $this->fcfa;
    }

    public function ajouter(self $autre): self
    {
        return new self($this->fcfa + $autre->fcfa);
    }

    public function soustraire(self $autre): self
    {
        return new self($this->fcfa - $autre->fcfa);
    }

    public function estSuperieurA(self $autre): bool
    {
        return $this->fcfa > $autre->fcfa;
    }

    public function estSuperieurOuEgalA(self $autre): bool
    {
        return $this->fcfa >= $autre->fcfa;
    }

    public function equals(self $autre): bool
    {
        return $this->fcfa === $autre->fcfa;
    }

    /**
     * Montant lisible pour un message adressé à l'utilisateur :
     * « 12 000 000 FCFA », séparateur de milliers insécable pour que le
     * nombre ne se coupe pas en fin de ligne.
     */
    public function formater(): string
    {
        return number_format($this->fcfa, 0, ',', "\u{202F}").' FCFA';
    }
}
