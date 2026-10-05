<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Vérifié avant la comparaison du mot de passe (checkPreAuth) : un compte
 * verrouillé ou désactivé ne doit pas déclencher de calcul Argon2id, et ne
 * doit jamais révéler si le mot de passe était correct (§ 7.1 du brief).
 */
final class UtilisateurChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Utilisateur) {
            return;
        }

        if (!$user->estActif()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est désactivé.');
        }

        if ($user->estVerrouille(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
            throw new CustomUserMessageAccountStatusException('Ce compte est temporairement verrouillé après plusieurs échecs de connexion. Réessayez dans quelques minutes.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
