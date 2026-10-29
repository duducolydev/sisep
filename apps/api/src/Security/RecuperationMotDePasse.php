<?php

declare(strict_types=1);

namespace App\Security;

use App\Courriel\ServiceCourriel;
use App\Entity\JetonReinitialisation;
use App\Entity\Utilisateur;
use App\Repository\JetonReinitialisationRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Mot de passe oublié » : le titulaire d'un compte reçoit par courriel un
 * lien qui lui permet d'en choisir un nouveau, sans passer par
 * l'administrateur.
 *
 * Le lien vaut une heure et ne sert qu'une fois ; une nouvelle demande
 * remplace la précédente, et un changement réussi les annule toutes. Rien
 * n'indique à l'appelant si l'adresse correspond à un compte : la réponse
 * HTTP est la même dans tous les cas.
 */
final class RecuperationMotDePasse
{
    public const VALIDITE_MINUTES = 60;

    /**
     * Une demande par compte dans cet intervalle, les suivantes sont ignorées
     * en silence : sans quoi n'importe qui pourrait inonder une boîte de
     * courriels SISEP en répétant la demande.
     */
    private const INTERVALLE_MINUTES = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly JetonReinitialisationRepository $jetons,
        private readonly UserPasswordHasherInterface $hacheur,
        private readonly ServiceCourriel $courriels,
    ) {
    }

    public function demander(string $email, \DateTimeImmutable $maintenant): void
    {
        $this->jetons->purgerExpires($maintenant);

        $utilisateur = $this->utilisateurs->findOneBy(['email' => $email]);
        if (null === $utilisateur || !self::peutRecuperer($utilisateur)) {
            return;
        }

        $dernier = $this->jetons->dernierPour($utilisateur);
        if (null !== $dernier && $dernier->getCreeLe() > $maintenant->modify(\sprintf('-%d minutes', self::INTERVALLE_MINUTES))) {
            return;
        }

        foreach ($this->jetons->tousPour($utilisateur) as $ancien) {
            $this->em->remove($ancien);
        }

        // 256 bits, en base64 sans les caractères que les URL réservent.
        $jeton = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->em->persist(new JetonReinitialisation($utilisateur, $jeton, $maintenant, self::VALIDITE_MINUTES));
        $this->em->flush();

        $this->courriels->lienRecuperation($utilisateur, $jeton, self::VALIDITE_MINUTES);
    }

    /** Le compte dont le mot de passe a changé, ou null si le lien n'est pas (ou plus) valable. */
    public function reinitialiser(string $jeton, string $nouveauMotDePasse, \DateTimeImmutable $maintenant): ?Utilisateur
    {
        $lien = $this->jetons->trouverParJeton($jeton);
        if (null === $lien || $lien->estExpire($maintenant) || !self::peutRecuperer($lien->getUtilisateur())) {
            return null;
        }

        $utilisateur = $lien->getUtilisateur();
        $utilisateur->setMotDePasseHash($this->hacheur->hashPassword($utilisateur, $nouveauMotDePasse));
        // Choisi par le titulaire lui-même, contrairement à un mot de passe
        // temporaire : rien à changer à la connexion suivante.
        $utilisateur->marquerMdpChange();
        $utilisateur->leverVerrouillage();
        foreach ($this->jetons->tousPour($utilisateur) as $consomme) {
            $this->em->remove($consomme);
        }
        $this->em->flush();

        $this->courriels->motDePasseChange($utilisateur, $maintenant);

        return $utilisateur;
    }

    /** Un compte désactivé ou supprimé ne se rouvre pas par ce chemin : c'est une décision d'administrateur. */
    private static function peutRecuperer(Utilisateur $utilisateur): bool
    {
        return $utilisateur->estActif() && !$utilisateur->estSupprime();
    }
}
