<?php

declare(strict_types=1);

namespace App\Vigilance;

use App\Enum\StatutInitiativeActivite;
use App\Repository\ParametreRepository;

/**
 * Statut calculé d'une activité/tâche du module Projets — jamais saisi par
 * un utilisateur (§ décision produit). Distinct de VigilanceCalculator
 * (module PTBA, non touché) : ici le statut ET la vigilance ne font plus
 * qu'une seule valeur à 5 états.
 */
final class StatutInitiativeCalculator
{
    private const CODE_SEUIL_DUREE = 'initiatives.seuil_duree_risque';
    private const CODE_SEUIL_ECART = 'initiatives.seuil_ecart_risque';
    private const DEFAUT_SEUIL_DUREE = 2 / 3;
    private const DEFAUT_SEUIL_ECART = -0.20;

    public function __construct(private readonly ParametreRepository $parametres)
    {
    }

    public function calculer(?\DateTimeImmutable $dateDebut, ?\DateTimeImmutable $echeance, ?float $avancement): StatutInitiativeActivite
    {
        if (1.0 === $avancement) {
            return StatutInitiativeActivite::TERMINEE;
        }

        $aujourdhui = new \DateTimeImmutable('today');

        if (null !== $echeance && $echeance < $aujourdhui) {
            return StatutInitiativeActivite::EN_RETARD;
        }

        if (null !== $dateDebut && $dateDebut > $aujourdhui) {
            return StatutInitiativeActivite::PLANIFIEE;
        }

        if (null !== $dateDebut && null !== $echeance && $echeance > $dateDebut) {
            $dureeTotale = $echeance->getTimestamp() - $dateDebut->getTimestamp();
            $ecoule = min(max($aujourdhui->getTimestamp() - $dateDebut->getTimestamp(), 0), $dureeTotale);
            $tauxEcoule = $ecoule / $dureeTotale;

            $seuilDuree = $this->parametres->valeurFlottante(self::CODE_SEUIL_DUREE, self::DEFAUT_SEUIL_DUREE);
            $seuilEcart = $this->parametres->valeurFlottante(self::CODE_SEUIL_ECART, self::DEFAUT_SEUIL_ECART);

            if ($tauxEcoule >= $seuilDuree && ($avancement ?? 0.0) - $tauxEcoule < $seuilEcart) {
                return StatutInitiativeActivite::A_RISQUE;
            }
        }

        return StatutInitiativeActivite::EN_COURS;
    }
}
