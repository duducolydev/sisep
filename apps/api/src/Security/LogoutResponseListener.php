<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * API pure : pas de redirection HTML après déconnexion, juste une
 * confirmation JSON. Le firewall.logout n'expose plus de success_handler en
 * Symfony 7 (option retirée) — on écoute LogoutEvent à la place.
 */
#[AsEventListener(event: LogoutEvent::class)]
final class LogoutResponseListener
{
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(['deconnecte' => true]));
    }
}
