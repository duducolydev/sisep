<?php

declare(strict_types=1);

namespace App\Tests\Unit\Budget;

use App\Budget\TrajectoirePrevue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrajectoirePrevueTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function moisEtCumulsAttendus(): iterable
    {
        // T1 120, T2 660, T3 240, T4 180 sur 1 200 : chaque mois d'un trimestre pèse autant.
        yield 'janvier' => [1, 40];
        yield 'mars, fin de T1' => [3, 120];
        yield 'avril' => [4, 340];
        yield 'juin, fin de T2' => [6, 780];
        yield 'septembre, fin de T3' => [9, 1_020];
        yield 'novembre' => [11, 1_140];
        yield 'décembre' => [12, 1_200];
    }

    #[DataProvider('moisEtCumulsAttendus')]
    public function testSuitLesTrimestres(int $mois, int $attendu): void
    {
        self::assertSame($attendu, $this->cumul(1_200, [120, 660, 240, 180], $mois));
    }

    public function testUneDotationReviseeGardeSonRythmeEtFinitSurLeNotifie(): void
    {
        self::assertSame(1_560, $this->cumul(2_400, [120, 660, 240, 180], 6), 'Le notifié a doublé : 65 % à fin juin, comme avant.');
        self::assertSame(2_400, $this->cumul(2_400, [120, 660, 240, 180], 12));
    }

    public function testUnTrimestreVideNeFaitPasMonterLaTrajectoire(): void
    {
        self::assertSame(0, $this->cumul(1_000, [0, 1_000, null, null], 3));
        self::assertSame(1_000, $this->cumul(1_000, [0, 1_000, null, null], 6));
        self::assertSame(1_000, $this->cumul(1_000, [0, 1_000, null, null], 9), 'Plus rien de prévu après T2.');
    }

    public function testSansTrimestresLaTrajectoireResteLineaire(): void
    {
        self::assertSame(12_000, $this->cumul(12_000, [null, null, null, null], 12));
        $juin = $this->cumul(12_000, [null, null, null, null], 6);
        self::assertGreaterThan(5_900, $juin);
        self::assertLessThan(6_100, $juin);
    }

    public function testUnExerciceHorsAnneeCivileResteLineaire(): void
    {
        $debut = new \DateTimeImmutable('2026-07-01');
        $fin = new \DateTimeImmutable('2027-06-30');

        self::assertSame(1_000, TrajectoirePrevue::cumul(1_000, [100, 200, 300, 400], $debut, $fin, $fin));
    }

    public function testDeGrosMontantsRestentExacts(): void
    {
        // PTBA 2026 : le produit dépasse largement les entiers PHP.
        $trimestres = [2_179_552_765, 5_991_333_650, 1_427_391_660, 1_290_578_861];
        self::assertSame(10_888_856_936, $this->cumul(10_888_856_936, $trimestres, 12));
        self::assertSame(8_170_886_415, $this->cumul(10_888_856_936, $trimestres, 6));
    }

    /** @param array{0: ?int, 1: ?int, 2: ?int, 3: ?int} $trimestres */
    private function cumul(int $montant, array $trimestres, int $mois): int
    {
        return TrajectoirePrevue::cumul(
            $montant,
            $trimestres,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
            (new \DateTimeImmutable(\sprintf('2026-%02d-01', $mois)))->modify('last day of this month'),
        );
    }
}
