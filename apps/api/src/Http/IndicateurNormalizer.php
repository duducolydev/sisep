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
        ];
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
