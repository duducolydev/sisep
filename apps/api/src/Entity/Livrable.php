<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\LivrableRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Livrable quantifié rattaché à une étape (§ 7 du CR du 22/09/2026 : « les
 * livrables doivent également pouvoir être quantifiés et suivis, afin de
 * disposer d'éléments objectifs »).
 *
 * Deux quantités seulement — attendue et réalisée : c'est leur écart qui
 * rend la réalisation appréciable autrement que par un pourcentage
 * déclaratif. Le taux de réalisation n'est pas stocké, il se calcule, et vaut
 * null quand la quantité attendue est nulle (jamais 0, cf. § 5.1).
 */
#[ORM\Entity(repositoryClass: LivrableRepository::class)]
#[ORM\Table(name: 'livrables')]
class Livrable
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\ManyToOne(targetEntity: EtapeActivite::class, inversedBy: 'livrables')]
    #[ORM\JoinColumn(nullable: false)]
    private EtapeActivite $etape;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $unite = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $quantiteAttendue;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $quantiteRealisee = '0';

    public function __construct(EtapeActivite $etape, string $libelle, string $quantiteAttendue)
    {
        $this->initialiserUuid();
        $this->etape = $etape;
        $this->libelle = $libelle;
        $this->quantiteAttendue = $quantiteAttendue;
        $etape->ajouterLivrable($this);
    }

    public function getEtape(): EtapeActivite
    {
        return $this->etape;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getUnite(): ?string
    {
        return $this->unite;
    }

    public function setUnite(?string $unite): void
    {
        $this->unite = $unite;
    }

    public function getQuantiteAttendue(): string
    {
        return $this->quantiteAttendue;
    }

    public function setQuantiteAttendue(string $quantiteAttendue): void
    {
        $this->quantiteAttendue = $quantiteAttendue;
    }

    public function getQuantiteRealisee(): string
    {
        return $this->quantiteRealisee;
    }

    public function setQuantiteRealisee(string $quantiteRealisee): void
    {
        $this->quantiteRealisee = $quantiteRealisee;
    }

    /** Division par zéro → null, jamais 0 (§ 5.1 du brief). */
    public function tauxRealisation(): ?float
    {
        $attendue = (float) $this->quantiteAttendue;

        return 0.0 === $attendue ? null : (float) $this->quantiteRealisee / $attendue;
    }
}
