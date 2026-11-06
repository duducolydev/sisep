<?php

declare(strict_types=1);

namespace App\Tests\Unit\Indicateur;

use App\Indicateur\NombreDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Saisie à la française des valeurs d'indicateurs (DECIMAL(18,4)) : « 0,5 »
 * faisait répondre « Erreur interne » à la définition d'un indicateur.
 */
final class NombreDecimalTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function saisiesAcceptees(): iterable
    {
        yield 'entier' => ['12', '12'];
        yield 'point décimal' => ['0.5', '0.5'];
        yield 'virgule décimale' => ['0,5', '0.5'];
        yield 'espace de milliers' => ['1 000', '1000'];
        yield 'espace insécable' => ["1\u{00A0}000,25", '1000.25'];
        yield 'espace fine insécable' => ["2\u{202F}500", '2500'];
        yield 'négatif et blancs autour' => ['  -3,75 ', '-3.75'];
        yield '14 chiffres et 4 décimales' => ['12345678901234,1234', '12345678901234.1234'];
    }

    #[DataProvider('saisiesAcceptees')]
    public function testNormaliseLesSaisiesValides(string $saisie, string $attendu): void
    {
        self::assertSame($attendu, NombreDecimal::normaliser($saisie));
    }

    /** @return iterable<string, array{string}> */
    public static function saisiesRefusees(): iterable
    {
        yield 'vide' => [''];
        yield 'unité collée' => ['100 %'];
        yield 'texte' => ['ND'];
        yield 'deux virgules' => ['1,2,3'];
        yield 'cinq décimales' => ['0,12345'];
        yield '15 chiffres' => ['123456789012345'];
        yield 'notation scientifique' => ['1e5'];
    }

    #[DataProvider('saisiesRefusees')]
    public function testRefuseCeQuiNeTientPasDansDecimal18Virgule4(string $saisie): void
    {
        self::assertNull(NombreDecimal::normaliser($saisie));
    }
}
