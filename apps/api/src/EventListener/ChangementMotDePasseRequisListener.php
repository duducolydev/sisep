<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Utilisateur;
use App\Http\ProblemResponseFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Impose le changement d'un mot de passe temporaire avant tout usage de
 * l'application.
 *
 * Tout compte est créé avec un mot de passe temporaire — communiqué par
 * l'administrateur, affiché dans un terminal, parfois envoyé par courriel —
 * et `doitChangerMdp` vrai. Le drapeau existait, mais rien ne le faisait
 * respecter : un compte pouvait vivre indéfiniment avec un mot de passe
 * que d'autres avaient vu. L'imposer côté interface seulement ne suffirait
 * pas — un appel direct à l'API contournerait l'écran — d'où ce filtre
 * serveur, l'interface se contentant d'y conduire l'utilisateur.
 *
 * Tant que le mot de passe n'est pas changé, seules restent ouvertes les
 * routes nécessaires pour le faire : lire son profil, changer le mot de
 * passe, se déconnecter.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final class ChangementMotDePasseRequisListener
{
    /** Code lu par l'interface pour rediriger vers l'écran de changement. */
    public const TYPE = 'changement-mot-de-passe-requis';

    /** Routes accessibles avec un mot de passe temporaire. */
    private const ROUTES_AUTORISEES = [
        'app_auth_connexion',
        'app_auth_moi',
        'app_auth_mot_de_passe',
        'app_auth_deconnexion',
        // Un lien de récupération ouvert dans un navigateur où traîne une
        // session à mot de passe temporaire doit fonctionner quand même.
        'app_auth_mot_de_passe_oublie',
        'app_auth_reinitialisation',
    ];

    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requete = $event->getRequest();
        if (!str_starts_with($requete->getPathInfo(), '/api/')) {
            return;
        }
        if (\in_array($requete->attributes->get('_route'), self::ROUTES_AUTORISEES, true)) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur || !$utilisateur->doitChangerMdp()) {
            return;
        }

        $event->setResponse(ProblemResponseFactory::creer(
            403,
            'Changement de mot de passe requis',
            'Votre mot de passe est temporaire : choisissez-en un nouveau pour accéder à l\'application.',
            ['type' => self::TYPE],
        ));
    }
}
