<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JournalActiviteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alimenté exclusivement par JournalActiviteSubscriber (onFlush), jamais par
 * un appel manuel dans un service métier (§ 7.3 du brief).
 */
#[ORM\Entity(repositoryClass: JournalActiviteRepository::class)]
#[ORM\Table(name: 'journal_activite')]
#[ORM\Index(name: 'idx_journal_entite', columns: ['entite_type', 'entite_id'])]
#[ORM\Index(name: 'idx_journal_cree_le', columns: ['cree_le'])]
class JournalActivite
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 60)]
    private string $action;

    #[ORM\Column(length: 40)]
    private string $entiteType;

    #[ORM\Column(type: 'bigint', options: ['unsigned' => true], nullable: true)]
    private ?int $entiteId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $avant = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $apres = null;

    #[ORM\Column(length: 45)]
    private string $adresseIp;

    #[ORM\Column(length: 255)]
    private string $agent;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $creeLe;

    /**
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>|null $apres
     */
    public function __construct(
        ?Utilisateur $utilisateur,
        string $action,
        string $entiteType,
        ?int $entiteId,
        ?array $avant,
        ?array $apres,
        string $adresseIp,
        string $agent,
    ) {
        $this->utilisateur = $utilisateur;
        $this->action = $action;
        $this->entiteType = $entiteType;
        $this->entiteId = $entiteId;
        $this->avant = $avant;
        $this->apres = $apres;
        $this->adresseIp = $adresseIp;
        $this->agent = $agent;
        $this->creeLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntiteType(): string
    {
        return $this->entiteType;
    }

    public function getEntiteId(): ?int
    {
        return $this->entiteId;
    }

    /** @return array<string, mixed>|null */
    public function getAvant(): ?array
    {
        return $this->avant;
    }

    /** @return array<string, mixed>|null */
    public function getApres(): ?array
    {
        return $this->apres;
    }

    public function getAdresseIp(): string
    {
        return $this->adresseIp;
    }

    public function getAgent(): string
    {
        return $this->agent;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
