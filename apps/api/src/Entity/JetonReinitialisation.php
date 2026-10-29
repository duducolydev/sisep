<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\JetonReinitialisationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lien de récupération de mot de passe (« Mot de passe oublié »), envoyé par
 * courriel au titulaire du compte.
 *
 * Seule l'empreinte SHA-256 du jeton est en base : qui lit la table ne peut
 * pas reconstituer un lien valable. Usage unique et durée courte, voir
 * RecuperationMotDePasse.
 *
 * Pas de suppression logique : ni donnée budgétaire ni historique. Un jeton
 * consommé, périmé ou remplacé n'a plus de raison d'exister ; la trace qui
 * compte, le changement de mot de passe, est au journal par Utilisateur.
 */
#[ORM\Entity(repositoryClass: JetonReinitialisationRepository::class)]
#[ORM\Table(name: 'jetons_reinitialisation')]
class JetonReinitialisation
{
    use IdentifiantsTrait;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $utilisateur;

    #[ORM\Column(length: 64, unique: true, options: ['fixed' => true])]
    private string $empreinte;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $expireLe;

    public function __construct(Utilisateur $utilisateur, string $jeton, \DateTimeImmutable $maintenant, int $validiteMinutes)
    {
        $this->initialiserUuid();
        $this->utilisateur = $utilisateur;
        $this->empreinte = self::empreinte($jeton);
        $this->creeLe = $maintenant;
        $this->expireLe = $maintenant->modify(\sprintf('+%d minutes', $validiteMinutes));
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function estExpire(\DateTimeImmutable $maintenant): bool
    {
        return $maintenant >= $this->expireLe;
    }
}
