<?php

declare(strict_types=1);

namespace App\Tests\Unit\ValueObject;

use App\ValueObject\Montant;
use PHPUnit\Framework\TestCase;

final class MontantTest extends TestCase
{
    public function testUnMontantNegatifEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Montant::fromFcfa(-1);
    }

    public function testAdditionEtSoustraction(): void
    {
        $a = Montant::fromFcfa(500_000);
        $b = Montant::fromFcfa(200_000);

        self::assertSame(700_000, $a->ajouter($b)->versFcfa());
        self::assertSame(300_000, $a->soustraire($b)->versFcfa());
    }

    public function testSoustraireAuDelaDeZeroEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Montant::fromFcfa(100)->soustraire(Montant::fromFcfa(200));
    }

    public function testComparaisons(): void
    {
        $petit = Montant::fromFcfa(100);
        $grand = Montant::fromFcfa(200);

        self::assertTrue($grand->estSuperieurA($petit));
        self::assertFalse($petit->estSuperieurA($grand));
        self::assertTrue($petit->estSuperieurOuEgalA($petit));
        self::assertTrue($petit->equals(Montant::fromFcfa(100)));
    }

    public function testFormaterPourUnMessage(): void
    {
        self::assertSame("12\u{202F}000\u{202F}000 FCFA", Montant::fromFcfa(12_000_000)->formater());
        self::assertSame('0 FCFA', Montant::fromFcfa(0)->formater());
    }
}
