<?php

declare(strict_types=1);

namespace App\Controller;

use App\Courriel\ServiceCourriel;
use App\Dto\ChangerMotDePasseRequest;
use App\Entity\Utilisateur;
use App\Http\ProblemResponseFactory;
use App\Security\ProfilUtilisateurBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly ProfilUtilisateurBuilder $profilBuilder,
        private readonly ServiceCourriel $courriels,
    ) {
    }

    /**
     * Route déclarée pour json_login (§ 7.1) : en fonctionnement normal, le
     * firewall intercepte la requête avant qu'elle n'atteigne cette action.
     * Si on l'atteint malgré tout, l'authentification par ce chemin n'est
     * pas configurée correctement.
     */
    #[Route('/connexion', name: 'app_auth_connexion', methods: ['POST'])]
    public function connexion(): Response
    {
        throw new \LogicException('La route de connexion doit être interceptée par le firewall json_login, pas atteindre le contrôleur.');
    }

    /** Route déclarée pour le firewall (interceptée avant le contrôleur), voir connexion(). */
    #[Route('/deconnexion', name: 'app_auth_deconnexion', methods: ['POST'])]
    public function deconnexion(): Response
    {
        throw new \LogicException('La route de déconnexion doit être interceptée par le firewall.');
    }

    #[Route('/moi', name: 'app_auth_moi', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function moi(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        return new JsonResponse($this->profilBuilder->construire($utilisateur));
    }

    #[Route('/mot-de-passe', name: 'app_auth_mot_de_passe', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function changerMotDePasse(Request $request, #[CurrentUser] Utilisateur $utilisateur): Response
    {
        /** @var ChangerMotDePasseRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), ChangerMotDePasseRequest::class, 'json');

        $violations = $this->validator->validate($donnees);
        if (\count($violations) > 0) {
            $details = [];
            foreach ($violations as $violation) {
                $details[] = ['champ' => $violation->getPropertyPath(), 'message' => $violation->getMessage()];
            }

            return ProblemResponseFactory::creer(422, 'Données invalides', null, ['violations' => $details]);
        }

        if (!$this->passwordHasher->isPasswordValid($utilisateur, $donnees->motDePasseActuel)) {
            return ProblemResponseFactory::creer(422, 'Mot de passe actuel incorrect');
        }

        $utilisateur->setMotDePasseHash($this->passwordHasher->hashPassword($utilisateur, $donnees->nouveauMotDePasse));
        $utilisateur->marquerMdpChange();
        $this->em->flush();

        // Trace de sécurité côté titulaire du compte : c'est ce courriel qui
        // lui permet de réagir si le changement n'est pas de son fait.
        $this->courriels->motDePasseChange($utilisateur, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return new JsonResponse(['modifie' => true]);
    }
}
