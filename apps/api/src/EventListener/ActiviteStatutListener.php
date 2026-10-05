<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Activite;
use App\Entity\SousActivite;
use App\Enum\StatutActivite;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Remontée automatique § 4.2 : quand toutes les sous-activités d'une
 * activité passent à "Terminée", l'activité bascule elle-même — pas une
 * nouvelle validation, juste la remontée d'un fait déjà validé
 * individuellement (chaque clôture de sous-activité a déjà exigé
 * activites.valider, cf. SousActiviteController). Symétriquement, si une
 * sous-activité quitte "Terminée" alors que l'activité y était, celle-ci
 * repasse à "En cours" pour ne pas afficher un statut devenu faux. Ne
 * touche jamais une activité "Suspendue"/"Annulée" : ces statuts restent
 * une décision administrative explicite, jamais dérivée.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ActiviteStatutListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, Activite> $activitesAVerifier */
        $activitesAVerifier = [];
        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if (!$entite instanceof SousActivite) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entite);
            if (!\array_key_exists('statut', $changeSet)) {
                continue;
            }
            $activite = $entite->getActivite();
            $activitesAVerifier[spl_object_id($activite)] = $activite;
        }

        foreach ($activitesAVerifier as $activite) {
            if (!\in_array($activite->getStatut(), [StatutActivite::PLANIFIEE, StatutActivite::EN_COURS, StatutActivite::TERMINEE], true)) {
                continue;
            }

            $sousActivitesActives = $activite->getSousActivites()->filter(static fn (SousActivite $sa) => !$sa->estSupprime());
            if (0 === $sousActivitesActives->count()) {
                continue;
            }

            $toutesTerminees = true;
            foreach ($sousActivitesActives as $sa) {
                if (StatutActivite::TERMINEE !== $sa->getStatut()) {
                    $toutesTerminees = false;
                    break;
                }
            }

            $statutCible = $toutesTerminees ? StatutActivite::TERMINEE : StatutActivite::EN_COURS;
            if ($statutCible === $activite->getStatut()) {
                continue;
            }

            $activite->setStatut($statutCible);
            if (!$uow->isScheduledForInsert($activite)) {
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(Activite::class), $activite);
            }
        }
    }
}
