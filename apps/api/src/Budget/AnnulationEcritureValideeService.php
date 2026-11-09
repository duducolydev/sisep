<?php

declare(strict_types=1);

namespace App\Budget;

use App\Entity\Depense;
use App\Entity\Engagement;
use App\Enum\StatutEcriture;
use App\Exception\ContrainteBudgetaireException;
use App\Repository\DepenseRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Correction d'une écriture déjà validée (décision du 09/11/2026) : elle est
 * annulée avec un motif, puis ressaisie. Un montant validé ne se réécrit jamais :
 * l'écriture fautive reste visible, avec son auteur, son valideur et son motif,
 * et le journal garde l'état avant et après.
 *
 * Une dépense s'annule avant son engagement : l'inverse laisserait du décaissé
 * sans engagement validé. Sous le verrou de l'engagement, comme la validation
 * des dépenses, pour qu'aucune ne passe pendant l'annulation. Le verrou est la
 * première instruction de la transaction (cf. EngagementValidationService).
 */
final class AnnulationEcritureValideeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepenseRepository $depenses,
    ) {
    }

    public function annulerEngagement(Engagement $engagement, string $motif): void
    {
        $this->exigerExerciceOuvert($engagement);

        $this->em->wrapInTransaction(function () use ($engagement, $motif): void {
            $this->em->refresh($engagement, LockMode::PESSIMISTIC_WRITE);

            if (StatutEcriture::VALIDE !== $engagement->getStatut()) {
                throw new ContrainteBudgetaireException('Seul un engagement validé passe par cette annulation.');
            }
            if ($this->depenses->compterVivantes($engagement) > 0) {
                throw new ContrainteBudgetaireException('Cet engagement porte des dépenses : annulez-les d\'abord.');
            }

            $engagement->annulerValide($motif);
        });
    }

    public function annulerDepense(Depense $depense, string $motif): void
    {
        $engagement = $depense->getEngagement();
        $this->exigerExerciceOuvert($engagement);

        $this->em->wrapInTransaction(function () use ($depense, $engagement, $motif): void {
            $this->em->refresh($engagement, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($depense);

            if (StatutEcriture::VALIDE !== $depense->getStatut()) {
                throw new ContrainteBudgetaireException('Seule une dépense validée passe par cette annulation.');
            }

            $depense->annulerValidee($motif);
        });
    }

    private function exigerExerciceOuvert(Engagement $engagement): void
    {
        if (!$engagement->getExercice()->estOuvert()) {
            throw new ContrainteBudgetaireException("Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }
    }
}
