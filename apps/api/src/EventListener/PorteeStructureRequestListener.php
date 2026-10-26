<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\Filter\PorteeActiviteFilter;
use App\Entity\Utilisateur;
use App\Security\PermissionsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Configure le filtre SQL de portée (§ 7.2) au tout début de chaque requête
 * HTTP, à partir des permissions effectives de l'utilisateur authentifié.
 * Le firewall étant "lazy", Security::getUser() déclenche
 * l'authentification à la demande — l'ordre exact face au listener du
 * firewall n'a pas besoin d'être négocié.
 *
 * Ne s'exécute jamais pour les commandes console (seed, migrations) : rien
 * ne dispatche kernel.request en CLI.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final class PorteeStructureRequestListener
{
    public function __construct(
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $utilisateur = $this->security->getUser();

        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $permissions = $this->permissionsResolver->pour($utilisateur);

        if ($permissions->estGlobale('activites.consulter')) {
            // Noyau réutilisé d'une requête à l'autre (tests, serveur
            // persistant) : ne jamais garder la portée du compte précédent.
            if ($this->em->getFilters()->isEnabled('portee_activite')) {
                $this->em->getFilters()->disable('portee_activite');
            }

            return;
        }

        $filtre = $this->em->getFilters()->enable('portee_activite');
        \assert($filtre instanceof PorteeActiviteFilter);
        $filtre->definirPortee($permissions->structuresAutorisees('activites.consulter'));
    }
}
