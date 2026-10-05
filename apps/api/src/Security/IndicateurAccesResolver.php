<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Indicateur;
use App\Entity\Utilisateur;
use App\Repository\InvitationIndicateurRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Centralise la règle d'accès à un indicateur précis (consultation et
 * saisie) pour l'utilisateur courant — partagée entre IndicateurController
 * (qui en tire un refus 403) et IndicateurNormalizer (qui en tire le hint
 * d'affichage "peutSaisir" exposé au front, § 8 : un sectoriel invité a un
 * accès serveur réel sans détenir indicateurs.saisir en liste de
 * permissions, donc le hint générique usePeutSur() ne suffit pas ici).
 */
final class IndicateurAccesResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly InvitationIndicateurRepository $invitations,
    ) {
    }

    public function peutConsulter(Indicateur $indicateur): bool
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        return $this->permissionsResolver->pour($utilisateur)->estGlobale('indicateurs.consulter')
            || $this->invitations->estInvite($indicateur, $utilisateur);
    }

    public function peutSaisir(Indicateur $indicateur): bool
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        return $this->security->isGranted('indicateurs.saisir', $indicateur)
            || $this->invitations->estInvite($indicateur, $utilisateur);
    }
}
