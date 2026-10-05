<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vigilance;

use App\Enum\Vigilance;
use App\Repository\ParametreRepository;
use App\Vigilance\VigilanceCalculator;
use PHPUnit\Framework\TestCase;

/** § 5.3 du brief : seuils par défaut -0,10 / -0,20, échéance dépassée prioritaire. */
final class VigilanceCalculatorTest extends TestCase
{
    private VigilanceCalculator $calculateur;

    protected function setUp(): void
    {
        $parametres = $this->createMock(ParametreRepository::class);
        $parametres->method('valeurFlottante')->willReturnMap([
            ['vigilance.seuil_retard', -0.10, -0.10],
            ['vigilance.seuil_risque', -0.20, -0.20],
        ]);

        $this->calculateur = new VigilanceCalculator($parametres);
    }

    public function testEcartFavorableEstNormal(): void
    {
        // taux_execution 60 %, taux_attendu 50 % → écart +0,10 → normal.
        self::assertSame(
            Vigilance::NORMAL,
            $this->calculateur->calculer(0.60, 0.50, false, false),
        );
    }

    public function testEcartExactementAMoinsDixEstNormal(): void
    {
        // Borne incluse dans "normal" (ecart ≥ −0,10).
        self::assertSame(
            Vigilance::NORMAL,
            $this->calculateur->calculer(0.40, 0.50, false, false),
        );
    }

    public function testEcartEntreMoinsVingtEtMoinsDixEstEnRetard(): void
    {
        // écart −0,15 → en retard.
        self::assertSame(
            Vigilance::EN_RETARD,
            $this->calculateur->calculer(0.35, 0.50, false, false),
        );
    }

    public function testEcartExactementAMoinsVingtEstEnRetard(): void
    {
        // Borne incluse dans "en retard" (−0,20 ≤ ecart).
        self::assertSame(
            Vigilance::EN_RETARD,
            $this->calculateur->calculer(0.30, 0.50, false, false),
        );
    }

    public function testEcartInferieurAMoinsVingtEstRisqueEleve(): void
    {
        // écart −0,25 → risque élevé.
        self::assertSame(
            Vigilance::RISQUE_ELEVE,
            $this->calculateur->calculer(0.25, 0.50, false, false),
        );
    }

    public function testEcheanceDepasseeEstPrioritaireMemeSiEcartFavorable(): void
    {
        // Écart largement favorable (+0,50) mais échéance dépassée et statut
        // non terminée → risque élevé prioritaire sur le reste (§ 5.3).
        self::assertSame(
            Vigilance::RISQUE_ELEVE,
            $this->calculateur->calculer(1.0, 0.50, true, false),
        );
    }

    public function testEcheanceDepasseeMaisTermineeNestPasUneAlerte(): void
    {
        self::assertSame(
            Vigilance::NORMAL,
            $this->calculateur->calculer(1.0, 0.50, true, true),
        );
    }

    public function testAbsenceDeTauxExecutionRetourneNormal(): void
    {
        // Pas de dotation notifiée : impossible de calculer un écart, pas d'alerte fantôme.
        self::assertSame(
            Vigilance::NORMAL,
            $this->calculateur->calculer(null, 0.50, false, false),
        );
    }
}
