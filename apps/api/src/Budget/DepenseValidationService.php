<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\Depense;
use App\Entity\Utilisateur;
use App\Enum\StatutEcriture;
use App\Exception\ContrainteBudgetaireException;
use App\Repository\DepenseRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * § 5.1 du brief : SOMME(dépenses validées de l'engagement) + montant ≤
 * montant de l'engagement. Verrou pessimiste sur la ligne d'engagement.
 */
final class DepenseValidationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepenseRepository $depenses,
    ) {
    }

    public function valider(Depense $depense, Utilisateur $validateur): void
    {
        if (StatutEcriture::BROUILLON !== $depense->getStatut()) {
            throw new ContrainteBudgetaireException('Seule une dépense en brouillon peut être validée.');
        }

        if ($depense->getCreePar()->getId() === $validateur->getId()) {
            throw new ContrainteBudgetaireException('La personne qui a saisi la dépense ne peut pas la valider.');
        }

        $engagement = $depense->getEngagement();

        if (StatutEcriture::VALIDE !== $engagement->getStatut()) {
            throw new ContrainteBudgetaireException("L'engagement rattaché doit être validé avant de pouvoir valider une dépense.");
        }

        if (!$engagement->getExercice()->estOuvert()) {
            throw new ContrainteBudgetaireException("Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }

        $this->em->wrapInTransaction(function () use ($depense, $engagement, $validateur): void {
            $this->em->lock($engagement, LockMode::PESSIMISTIC_WRITE);

            $sommeValidee = $this->depenses->sommeValidee($engagement);
            $nouveauTotal = $sommeValidee->ajouter($depense->getMontant());

            if ($nouveauTotal->estSuperieurA($engagement->getMontant())) {
                throw new ContrainteBudgetaireException(\sprintf('Le montant décaissé atteindrait %d FCFA, au-delà du montant engagé de %d FCFA.', $nouveauTotal->versFcfa(), $engagement->getMontant()->versFcfa()));
            }

            $depense->valider($validateur);
        });
    }
}
