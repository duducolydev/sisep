<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Uid\Uuid;

/**
 * Le UuidType du pont Doctrine de Symfony stocke en BINARY(16) sur MySQL
 * (pas de type GUID natif). Le brief impose explicitement `uuid CHAR(36)`
 * (§ 3.3) — cette classe force cette représentation lisible plutôt que le
 * format compact par défaut.
 */
final class UuidCharType extends Type
{
    public const NAME = 'uuid_char';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'CHAR(36)';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Uuid
    {
        if (null === $value || $value instanceof Uuid) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', Uuid::class]);
        }

        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException $e) {
            throw ValueNotConvertible::new($value, self::NAME, previous: $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof Uuid) {
            throw InvalidType::new($value, self::NAME, [Uuid::class]);
        }

        return $value->toRfc4122();
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
