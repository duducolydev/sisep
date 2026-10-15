<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Indicateur;
use App\Entity\IndicateurValeur;
use App\Enum\StatutValeurIndicateur;
use App\Indicateur\TauxAtteinteCalculator;
use App\Security\IndicateurAccesResolver;

/**
 * "Valeur actuelle" et taux d'atteinte calculés à la demande à partir de la
 * dernière période validée (§ 5.2, § 2.4) — jamais stockés.
 *
 * `enAttente` (retours des RSE, octobre 2026) : la saisie la plus récente
 * quand elle n'est pas encore validée, avec son taux d'atteinte provisoire.
 * L'interface la montre aussitôt, marquée « en attente de validation » ;
 * `actuel` et `tauxAtteinte` restent les valeurs validées, seules reprises
 * par les exports et les rapports.
 */
final class IndicateurNormalizer
{
    public function __construct(private readonly IndicateurAccesResolver $accesResolver)
    {
    }

    /** @return array<string, mixed> */
    public function normaliser(Indicateur $indicateur): array
    {
        $derniereValidee = $this->derniereValidee($indicateur);
        $actuel = $derniereValidee?->getValeur();
        $enAttente = $this->enAttente($indicateur);

        return [
            'uuid' => $indicateur->getUuid()->toRfc4122(),
            'code' => $indicateur->getCode(),
            'libelle' => $indicateur->getLibelle(),
            'precisionLibelle' => $indicateur->getPrecisionLibelle(),
            'type' => $indicateur->getType()->value,
            'composante' => null === $indicateur->getComposante() ? null : [
                'uuid' => $indicateur->getComposante()->getUuid()->toRfc4122(),
                'code' => $indicateur->getComposante()->getCode(),
            ],
            'unite' => $indicateur->getUnite(),
            'sens' => $indicateur->getSens()->value,
            'valeurReference' => $indicateur->getValeurReference(),
            'dateReference' => $indicateur->getDateReference()->format('Y-m-d'),
            'valeurIntermediaire' => $indicateur->getValeurIntermediaire(),
            'dateIntermediaire' => $indicateur->getDateIntermediaire()?->format('Y-m-d'),
            'valeurCible' => $indicateur->getValeurCible(),
            'dateCible' => $indicateur->getDateCible()->format('Y-m-d'),
            'frequence' => $indicateur->getFrequence()->value,
            'source' => $indicateur->getSource(),
            'structureResponsable' => null === $indicateur->getStructureResponsable() ? null : [
                'uuid' => $indicateur->getStructureResponsable()->getUuid()->toRfc4122(),
                'sigle' => $indicateur->getStructureResponsable()->getSigle(),
            ],
            'actif' => $indicateur->estActif(),
            'actuel' => $actuel,
            'periodeActuelle' => $derniereValidee?->getPeriode()->format('Y-m-d'),
            'tauxAtteinte' => TauxAtteinteCalculator::calculer(
                $indicateur->getSens(),
                $indicateur->getValeurReference(),
                $indicateur->getValeurCible(),
                $actuel,
            ),
            // Hint d'affichage pour l'utilisateur courant (§ 8) : un
            // sectoriel invité peut saisir sans détenir indicateurs.saisir
            // en liste de permissions — usePeutSur() seul ne le détecterait
            // pas côté front, d'où ce champ calculé serveur par indicateur.
            'peutSaisir' => $this->accesResolver->peutSaisir($indicateur),
            'enAttente' => null === $enAttente ? null : [
                'uuid' => $enAttente->getUuid()->toRfc4122(),
                'valeur' => $enAttente->getValeur(),
                'periode' => $enAttente->getPeriode()->format('Y-m-d'),
                'tauxAtteinte' => TauxAtteinteCalculator::calculer(
                    $indicateur->getSens(),
                    $indicateur->getValeurReference(),
                    $indicateur->getValeurCible(),
                    $enAttente->getValeur(),
                ),
            ],
        ];
    }

    /**
     * Saisie la plus récente si elle attend encore sa validation. Une
     * valeur en brouillon plus ancienne que la dernière validée n'est pas
     * « en attente » au sens de l'affichage : elle ne changerait pas
     * l'actuel une fois validée.
     */
    private function enAttente(Indicateur $indicateur): ?IndicateurValeur
    {
        $valeurs = $indicateur->getValeurs()->toArray();
        if ([] === $valeurs) {
            return null;
        }

        usort($valeurs, static fn (IndicateurValeur $a, IndicateurValeur $b) => [$b->getPeriode(), $b->getId()] <=> [$a->getPeriode(), $a->getId()]);

        return StatutValeurIndicateur::BROUILLON === $valeurs[0]->getStatut() ? $valeurs[0] : null;
    }

    private function derniereValidee(Indicateur $indicateur): ?IndicateurValeur
    {
        $validees = $indicateur->getValeurs()->filter(
            static fn (IndicateurValeur $v) => StatutValeurIndicateur::VALIDE === $v->getStatut(),
        )->toArray();

        if ([] === $validees) {
            return null;
        }

        usort($validees, static fn (IndicateurValeur $a, IndicateurValeur $b) => $b->getPeriode() <=> $a->getPeriode());

        return $validees[0];
    }
}
