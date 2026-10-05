<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Activite;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\Realisation;
use App\Entity\SousActivite;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * § 6.2 du brief : "le mettre en cache côté serveur avec invalidation sur
 * toute écriture budgétaire" (à propos de /arbre, cf. ArbreReader). Purge au
 * postFlush — jamais avant, pour ne pas invalider sur un flush qui échoue
 * en base — et seulement si une Dotation, un Engagement, une Dépense, une
 * Réalisation, une Activité ou une Sous-activité a réellement été créée,
 * modifiée ou supprimée dans ce flush. La Réalisation compte aussi : l'arbre
 * affiche l'avancement des sous-activités (niveaux ≥ 4), pas seulement
 * l'exécution budgétaire. Activité/Sous-activité comptent pour la même
 * raison : l'arbre affiche aussi leur `statut` (§ 4.2 — cycle de vie saisi,
 * distinct de l'avancement), qui peut changer sans passer par une
 * Réalisation (ex. clôture via activites.valider).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ArbreCacheInvalidationListener
{
    private bool $invalidationNecessaire = false;

    public function __construct(private readonly TagAwareCacheInterface $arbreCache)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        $entites = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];

        foreach ($entites as $entite) {
            if (
                $entite instanceof Dotation
                || $entite instanceof Engagement
                || $entite instanceof Depense
                || $entite instanceof Realisation
                || $entite instanceof Activite
                || $entite instanceof SousActivite
            ) {
                $this->invalidationNecessaire = true;

                return;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->invalidationNecessaire) {
            return;
        }

        $this->invalidationNecessaire = false;
        $this->arbreCache->invalidateTags(['arbre']);
    }
}
