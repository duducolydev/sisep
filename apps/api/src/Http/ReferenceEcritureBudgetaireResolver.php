<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\JournalActivite;
use App\Repository\DepenseRepository;
use App\Repository\DotationRepository;
use App\Repository\EngagementRepository;

/**
 * Rend lisible une entrée de journal budgétaire : « dotation #37 » ne dit
 * rien à l'UGP, « 1.2.2 — EHCVM, 4 680 000 FCFA » répond à la question
 * « qui a saisi quoi ».
 *
 * Les écritures sont relues en lot, un SELECT par type et par page, et non
 * une par entrée : le journal se consulte par pages de 25 à 100 lignes, et
 * autant de requêtes unitaires se verrait. La ligne peut avoir disparu
 * (suppression logique, reseed) — la référence vaut alors null, et l'entrée
 * reste affichée : un journal d'audit ne perd pas une ligne parce que son
 * objet n'existe plus.
 */
final class ReferenceEcritureBudgetaireResolver
{
    /** Types d'entité que le journal considère comme des écritures budgétaires. */
    public const TYPES = ['dotation', 'engagement', 'depense'];

    public function __construct(
        private readonly DotationRepository $dotations,
        private readonly EngagementRepository $engagements,
        private readonly DepenseRepository $depenses,
    ) {
    }

    /**
     * @param list<JournalActivite> $entrees
     *
     * @return array<string, array<string, mixed>|null> "type:id" => référence
     */
    public function pour(array $entrees): array
    {
        $idsParType = [];
        foreach ($entrees as $entree) {
            $id = $entree->getEntiteId();
            if (null !== $id && \in_array($entree->getEntiteType(), self::TYPES, true)) {
                $idsParType[$entree->getEntiteType()][$id] = $id;
            }
        }

        $references = [];
        foreach ($this->dotations->findBy(['id' => array_values($idsParType['dotation'] ?? [])]) as $dotation) {
            $references['dotation:'.$dotation->getId()] = $this->referenceDotation($dotation);
        }
        foreach ($this->engagements->findBy(['id' => array_values($idsParType['engagement'] ?? [])]) as $engagement) {
            $references['engagement:'.$engagement->getId()] = $this->referenceEngagement($engagement);
        }
        foreach ($this->depenses->findBy(['id' => array_values($idsParType['depense'] ?? [])]) as $depense) {
            $references['depense:'.$depense->getId()] = $this->referenceDepense($depense);
        }

        return $references;
    }

    /** @return array<string, mixed> */
    private function referenceDotation(Dotation $dotation): array
    {
        $activite = $dotation->getActiviteParente();

        return [
            'activite' => ['code' => $activite->getCode(), 'libelle' => $activite->getLibelle()],
            'exercice' => $dotation->getExercice()->getAnnee(),
            // Chaîne et non entier : un montant FCFA dépasse la précision
            // sûre d'un nombre JSON côté navigateur (§ montants entiers).
            'montant' => (string) $dotation->getMontant()->versFcfa(),
            'libelle' => $dotation->getSousActivite()?->getLibelle(),
            'statut' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function referenceEngagement(Engagement $engagement): array
    {
        $activite = $engagement->getActiviteParente();

        return [
            'activite' => ['code' => $activite->getCode(), 'libelle' => $activite->getLibelle()],
            'exercice' => $engagement->getExercice()->getAnnee(),
            'montant' => (string) $engagement->getMontant()->versFcfa(),
            'libelle' => $engagement->getReferenceMarche() ?? $engagement->getNature()->value,
            'statut' => $engagement->getStatut()->value,
        ];
    }

    /** @return array<string, mixed> */
    private function referenceDepense(Depense $depense): array
    {
        $engagement = $depense->getEngagement();
        $activite = $engagement->getActiviteParente();

        return [
            'activite' => ['code' => $activite->getCode(), 'libelle' => $activite->getLibelle()],
            'exercice' => $engagement->getExercice()->getAnnee(),
            'montant' => (string) $depense->getMontant()->versFcfa(),
            'libelle' => $depense->getReferencePaiement(),
            'statut' => $depense->getStatut()->value,
        ];
    }
}
