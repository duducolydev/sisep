<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\Engagement;
use App\Entity\Utilisateur;
use App\Enum\StatutEcriture;
use App\Exception\ContrainteBudgetaireException;
use App\Repository\DotationRepository;
use App\Repository\EngagementRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * § 5.1 du brief : SOMME(engagements validés de l'activité pour l'exercice)
 * + montant ≤ dotation de l'activité pour l'exercice. Contrôle serveur, en
 * transaction avec verrou pessimiste sur la ligne de dotation — jamais
 * réalisé côté front (le front peut le rejouer pour le confort, jamais pour
 * la sécurité).
 */
final class EngagementValidationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DotationRepository $dotations,
        private readonly EngagementRepository $engagements,
    ) {
    }

    public function valider(Engagement $engagement, Utilisateur $validateur): void
    {
        if (StatutEcriture::BROUILLON !== $engagement->getStatut()) {
            throw new ContrainteBudgetaireException('Seul un engagement en brouillon peut être validé.');
        }

        // Séparation saisie/validation (§ 7.2) : exigence de contrôle interne
        // sur un projet financé par bailleur, vérifiée même si un rôle
        // détenait par erreur les deux permissions à la fois.
        if ($engagement->getCreePar()->getId() === $validateur->getId()) {
            throw new ContrainteBudgetaireException("La personne qui a saisi l'engagement ne peut pas le valider.");
        }

        if (!$engagement->getExercice()->estOuvert()) {
            throw new ContrainteBudgetaireException("Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }

        $this->em->wrapInTransaction(function () use ($engagement, $validateur): void {
            $dotation = $this->dotations->trouverPourCibleExercice($engagement->getCible(), $engagement->getExercice());
            if (null === $dotation) {
                throw new ContrainteBudgetaireException("Aucune dotation n'a été notifiée pour cette cible sur cet exercice.");
            }

            $this->em->lock($dotation, LockMode::PESSIMISTIC_WRITE);

            $sommeValidee = $this->engagements->sommeValidee($engagement->getCible(), $engagement->getExercice());
            $nouveauTotal = $sommeValidee->ajouter($engagement->getMontant());

            if ($nouveauTotal->estSuperieurA($dotation->getMontant())) {
                throw new ContrainteBudgetaireException(\sprintf('Le montant engagé atteindrait %s, au-delà de la dotation notifiée de %s.', $nouveauTotal->formater(), $dotation->getMontant()->formater()));
            }

            $engagement->valider($validateur);
        });
    }
}
