<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\Utilisateur;
use App\Enum\StatutEcriture;
use App\Exception\ContrainteBudgetaireException;
use App\Repository\DotationRepository;
use App\Repository\EngagementRepository;
use App\Security\PermissionsResolver;
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
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    /**
     * Saisie validée d'emblée (budget.validation_directe — le RSE, depuis le
     * 28/10/2026) : création et validation dans la même transaction, sous
     * le même verrou sur la dotation. Au-delà du plafond, rien n'est créé.
     */
    public function enregistrerValide(Engagement $engagement, Utilisateur $auteur): void
    {
        if (!$this->valideDirectement($auteur)) {
            throw new ContrainteBudgetaireException('Votre saisie doit être validée par une autre personne.');
        }
        if (!$engagement->getExercice()->estOuvert()) {
            throw new ContrainteBudgetaireException("Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }

        $this->em->wrapInTransaction(function () use ($engagement, $auteur): void {
            $dotation = $this->dotation($engagement);
            $this->em->lock($dotation, LockMode::PESSIMISTIC_WRITE);
            $this->controlerPlafond($engagement, $dotation);

            $engagement->valider($auteur);
            $this->em->persist($engagement);
        });
    }

    public function valideDirectement(Utilisateur $utilisateur): bool
    {
        return $this->permissionsResolver->pour($utilisateur)->estGlobale('budget.validation_directe');
    }

    public function valider(Engagement $engagement, Utilisateur $validateur): void
    {
        $this->controlerAvantVerrou($engagement, $validateur);

        $this->em->wrapInTransaction(function () use ($engagement, $validateur): void {
            $dotation = $this->dotation($engagement);
            $this->em->lock($dotation, LockMode::PESSIMISTIC_WRITE);
            $this->controlerPlafond($engagement, $dotation);

            $engagement->valider($validateur);
        });
    }

    /**
     * Les mêmes contrôles, sans verrou ni écriture : de quoi écarter d'un lot
     * les engagements que la validation refuserait. Dans une transaction, un
     * refus ferme le gestionnaire d'entités et interromprait tout le lot. Le
     * contrôle qui fait foi reste celui de valider(), sous verrou.
     */
    public function motifDeRefus(Engagement $engagement, Utilisateur $validateur): ?string
    {
        try {
            $this->controlerAvantVerrou($engagement, $validateur);
            $this->controlerPlafond($engagement, $this->dotation($engagement));
        } catch (ContrainteBudgetaireException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function controlerAvantVerrou(Engagement $engagement, Utilisateur $validateur): void
    {
        if (StatutEcriture::BROUILLON !== $engagement->getStatut()) {
            throw new ContrainteBudgetaireException('Seul un engagement en brouillon peut être validé.');
        }

        // Séparation saisie/validation (§ 7.2) : exigence de contrôle interne
        // sur un projet financé par bailleur, vérifiée même si un rôle
        // détenait par erreur les deux permissions à la fois — sauf pour qui
        // détient budget.validation_directe (le RSE, décision du 28/10/2026).
        if ($engagement->getCreePar()->getId() === $validateur->getId() && !$this->valideDirectement($validateur)) {
            throw new ContrainteBudgetaireException("La personne qui a saisi l'engagement ne peut pas le valider.");
        }

        if (!$engagement->getExercice()->estOuvert()) {
            throw new ContrainteBudgetaireException("Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }
    }

    private function dotation(Engagement $engagement): Dotation
    {
        return $this->dotations->trouverPourCibleExercice($engagement->getCible(), $engagement->getExercice())
            ?? throw new ContrainteBudgetaireException("Aucune dotation n'a été notifiée pour cette cible sur cet exercice.");
    }

    private function controlerPlafond(Engagement $engagement, Dotation $dotation): void
    {
        $sommeValidee = $this->engagements->sommeValidee($engagement->getCible(), $engagement->getExercice());
        $nouveauTotal = $sommeValidee->ajouter($engagement->getMontant());

        if ($nouveauTotal->estSuperieurA($dotation->getMontant())) {
            throw new ContrainteBudgetaireException(\sprintf('Le montant engagé atteindrait %s, au-delà de la dotation notifiée de %s.', $nouveauTotal->formater(), $dotation->getMontant()->formater()));
        }
    }
}
