<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ProblemResponseFactory;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Message générique côté client quelle que soit la cause (email inconnu ou
 * mot de passe erroné), pour ne pas révéler si un email existe. Le compteur
 * d'échecs n'est incrémenté que si le compte existe et n'est pas déjà
 * verrouillé (§ 7.1 du brief).
 */
final class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$exception instanceof CustomUserMessageAccountStatusException) {
            $email = $this->extraireEmail($request);
            $utilisateur = null !== $email ? $this->utilisateurs->findOneBy(['email' => $email]) : null;

            if (null !== $utilisateur) {
                $utilisateur->enregistrerEchecConnexion(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                $this->em->flush();
            }
        }

        $message = $exception instanceof CustomUserMessageAccountStatusException
            ? $exception->getMessage()
            : 'Adresse e-mail ou mot de passe incorrect.';

        return ProblemResponseFactory::creer(Response::HTTP_UNAUTHORIZED, 'Authentification refusée', $message);
    }

    private function extraireEmail(Request $request): ?string
    {
        $donnees = json_decode($request->getContent(), true);

        return \is_array($donnees) && \is_string($donnees['email'] ?? null) ? $donnees['email'] : null;
    }
}
