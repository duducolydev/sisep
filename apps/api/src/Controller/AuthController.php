<?php

declare(strict_types=1);

namespace App\Controller;

use App\Courriel\ServiceCourriel;
use App\Dto\ChangerMotDePasseRequest;
use App\Dto\DemanderRecuperationRequest;
use App\Dto\ReinitialiserMotDePasseRequest;
use App\Entity\Utilisateur;
use App\Http\ProblemResponseFactory;
use App\Security\ProfilUtilisateurBuilder;
use App\Security\RecuperationMotDePasse;
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
        private readonly RecuperationMotDePasse $recuperation,
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
        if (null !== ($erreur = $this->invalide($donnees))) {
            return $erreur;
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

    /**
     * Demande d'un lien de récupération. 202 dans tous les cas, que l'adresse
     * corresponde à un compte ou non : cette route ne doit pas servir à
     * vérifier qui a un compte SISEP.
     */
    #[Route('/mot-de-passe-oublie', name: 'app_auth_mot_de_passe_oublie', methods: ['POST'])]
    public function motDePasseOublie(Request $request): Response
    {
        /** @var DemanderRecuperationRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), DemanderRecuperationRequest::class, 'json');
        if (null !== ($erreur = $this->invalide($donnees))) {
            return $erreur;
        }

        $this->recuperation->demander(trim($donnees->email), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return new JsonResponse(['demandeEnregistree' => true], 202);
    }

    #[Route('/reinitialisation', name: 'app_auth_reinitialisation', methods: ['POST'])]
    public function reinitialiser(Request $request): Response
    {
        /** @var ReinitialiserMotDePasseRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), ReinitialiserMotDePasseRequest::class, 'json');
        if (null !== ($erreur = $this->invalide($donnees))) {
            return $erreur;
        }

        $utilisateur = $this->recuperation->reinitialiser(
            $donnees->jeton,
            $donnees->nouveauMotDePasse,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
        if (null === $utilisateur) {
            return ProblemResponseFactory::creer(
                422,
                'Lien invalide ou expiré',
                'Ce lien de récupération n\'est plus valable : il a déjà servi, une demande plus récente l\'a remplacé, ou son heure de validité est passée. Demandez-en un nouveau.',
            );
        }

        return new JsonResponse(['modifie' => true]);
    }

    private function invalide(object $donnees): ?Response
    {
        $violations = $this->validator->validate($donnees);
        if (0 === \count($violations)) {
            return null;
        }

        $details = [];
        foreach ($violations as $violation) {
            $details[] = ['champ' => $violation->getPropertyPath(), 'message' => $violation->getMessage()];
        }

        return ProblemResponseFactory::creer(422, 'Données invalides', null, ['violations' => $details]);
    }
}
