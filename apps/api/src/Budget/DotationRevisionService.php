<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\Dotation;
use App\Exception\ContrainteBudgetaireException;
use App\Repository\EngagementRepository;
use App\ValueObject\Montant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Révision du montant notifié d'une dotation (décision du 09/11/2026) :
 * correction d'une erreur de saisie ou application d'un budget révisé.
 *
 * Sous le même verrou que la validation des engagements : le plafond ne peut
 * pas descendre sous ce qui est déjà engagé et validé, même si une validation
 * arrive au même moment. Le journal garde l'état avant et après, le motif dit
 * pourquoi.
 */
final class DotationRevisionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EngagementRepository $engagements,
    ) {
    }

    public function reviser(Dotation $dotation, Montant $montant, string $motif, ?Montant $montantValideCopil): void
    {
        if (!$dotation->getExercice()->estOuvert()) {
            throw new ContrainteBudgetaireException("Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }

        $this->em->wrapInTransaction(function () use ($dotation, $montant, $motif, $montantValideCopil): void {
            $this->em->refresh($dotation, LockMode::PESSIMISTIC_WRITE);

            $engage = $this->engagements->sommeValidee($dotation->getCible(), $dotation->getExercice());
            if ($engage->estSuperieurA($montant)) {
                throw new ContrainteBudgetaireException(\sprintf('La dotation ne peut pas descendre à %s : %s sont déjà engagés et validés sur cette ligne.', $montant->formater(), $engage->formater()));
            }

            $dotation->reviser($montant, $motif, $montantValideCopil);
        });
    }
}
