<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\ValueObject\Montant;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * Colonne BIGINT UNSIGNED <-> App\ValueObject\Montant.
 *
 * Doctrine hydrate normalement le DBAL "bigint" en string PHP pour préserver
 * la précision sur les plateformes 32 bits. On force ici un int PHP (64 bits) :
 * un montant FCFA ne doit jamais circuler comme string dans le code métier.
 */
final class MontantType extends Type
{
    public const NAME = 'montant';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'BIGINT UNSIGNED';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Montant
    {
        if (null === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            throw ValueNotConvertible::new($value, self::NAME);
        }

        return Montant::fromFcfa((int) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof Montant) {
            throw InvalidType::new($value, self::NAME, [Montant::class]);
        }

        return $value->versFcfa();
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
