<?php

declare(strict_types=1);

namespace App\Tests\Unit\Indicateur;

use App\Enum\SensIndicateur;
use App\Indicateur\TauxAtteinteCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Critère d'acceptation P4 (§ 10 du brief) : les deux sens de variation
 * donnent le bon taux, y compris au-delà de 100 % et sur cible = reference.
 */
final class TauxAtteinteCalculatorTest extends TestCase
{
    public function testSensCroissant(): void
    {
        // IOD-1 de la maquette : ref 407000, actuel 448300, cible 620000 → 72 %.
        $taux = TauxAtteinteCalculator::calculer(SensIndicateur::CROISSANT, '407000', '620000', '448300');

        self::assertEqualsWithDelta(0.19, $taux, 0.01);
    }

    public function testSensDecroissant(): void
    {
        // IOD-3 : ref 14, actuel 8.5, cible 6 (mois, moins c'est mieux) → 69 %.
        $taux = TauxAtteinteCalculator::calculer(SensIndicateur::DECROISSANT, '14', '6', '8.5');

        self::assertEqualsWithDelta(0.6875, $taux, 0.001);
    }

    public function testAuDelaDeCentPourCentNestPasBorne(): void
    {
        // Croissant : cible dépassée doit donner un taux réel > 1, pas plafonné.
        $taux = TauxAtteinteCalculator::calculer(SensIndicateur::CROISSANT, '0', '100', '130');

        self::assertSame(1.3, $taux);
    }

    public function testAuDelaDeCentPourCentSensDecroissant(): void
    {
        // Décroissant : actuel meilleur que la cible doit aussi dépasser 100 %.
        $taux = TauxAtteinteCalculator::calculer(SensIndicateur::DECROISSANT, '100', '50', '20');

        self::assertSame(1.6, $taux);
    }

    public function testCibleEgaleReferenceRetourneNull(): void
    {
        self::assertNull(TauxAtteinteCalculator::calculer(SensIndicateur::CROISSANT, '50', '50', '75'));
        self::assertNull(TauxAtteinteCalculator::calculer(SensIndicateur::DECROISSANT, '50', '50', '75'));
    }

    public function testAucuneValeurActuelleRetourneNull(): void
    {
        self::assertNull(TauxAtteinteCalculator::calculer(SensIndicateur::CROISSANT, '0', '100', null));
    }
}
