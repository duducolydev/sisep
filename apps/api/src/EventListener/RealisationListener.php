<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Realisation;
use App\Entity\SousActivite;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * "Trigger applicatif" du § 4.6 : à chaque insertion d'une Realisation, la
 * sous-activité correspondante voit son avancement courant mis à jour dans
 * la même transaction. onFlush (plutôt que prePersist/postPersist) parce
 * qu'on doit muter une entité tierce déjà gérée par l'UnitOfWork et faire
 * en sorte que Doctrine inclue cette mutation dans le flush en cours.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class RealisationListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof Realisation) {
                continue;
            }

            $sousActivite = $entity->getSousActivite();
            $sousActivite->definirAvancementCourant($entity->getAvancement());

            if (!$uow->isScheduledForInsert($sousActivite)) {
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(SousActivite::class), $sousActivite);
            }
        }
    }
}
