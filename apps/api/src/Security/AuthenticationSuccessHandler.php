<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final class AuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfilUtilisateurBuilder $profilBuilder,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new \LogicException('Utilisateur attendu après authentification.');
        }

        $utilisateur->enregistrerConnexion();
        $this->em->flush();

        return new JsonResponse(['utilisateur' => $this->profilBuilder->construire($utilisateur)]);
    }
}
