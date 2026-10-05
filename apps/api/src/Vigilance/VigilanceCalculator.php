<?php

declare(strict_types=1);

namespace App\Vigilance;

use App\Enum\Vigilance;
use App\Repository\ParametreRepository;

/**
 * § 5.3 du brief :
 *   taux_attendu = jours écoulés dans l'exercice / jours totaux de l'exercice
 *   ecart        = taux_execution − taux_attendu
 *
 *   ecart ≥ −0,10                          → normal
 *   −0,20 ≤ ecart < −0,10                  → en retard
 *   ecart < −0,20                          → risque élevé
 *   échéance dépassée et statut ≠ terminee → risque élevé (prioritaire)
 *
 * Les seuils sont lus depuis la table `parametres`, jamais des constantes en
 * dur — la coordination doit pouvoir les ajuster sans déploiement (P6).
 */
final class VigilanceCalculator
{
    private const CODE_SEUIL_RETARD = 'vigilance.seuil_retard';
    private const CODE_SEUIL_RISQUE = 'vigilance.seuil_risque';
    private const DEFAUT_SEUIL_RETARD = -0.10;
    private const DEFAUT_SEUIL_RISQUE = -0.20;

    public function __construct(private readonly ParametreRepository $parametres)
    {
    }

    public function calculer(
        ?float $tauxExecution,
        ?float $tauxAttendu,
        bool $echeanceDepassee,
        bool $estTerminee,
    ): Vigilance {
        if ($echeanceDepassee && !$estTerminee) {
            return Vigilance::RISQUE_ELEVE;
        }

        if (null === $tauxExecution || null === $tauxAttendu) {
            return Vigilance::NORMAL;
        }

        $ecart = $tauxExecution - $tauxAttendu;
        $seuilRetard = $this->parametres->valeurFlottante(self::CODE_SEUIL_RETARD, self::DEFAUT_SEUIL_RETARD);
        $seuilRisque = $this->parametres->valeurFlottante(self::CODE_SEUIL_RISQUE, self::DEFAUT_SEUIL_RISQUE);

        return match (true) {
            $ecart >= $seuilRetard => Vigilance::NORMAL,
            $ecart >= $seuilRisque => Vigilance::EN_RETARD,
            default => Vigilance::RISQUE_ELEVE,
        };
    }
}
