<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\HorodatageTrait;
use App\Entity\Trait\IdentifiantsTrait;
use App\Entity\Trait\SuppressionLogiqueTrait;
use App\Repository\GabaritEtapesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Modèle d'étapes réutilisable, défini par l'UGP (§ 6 du CR du 22/09/2026) :
 * préparation, production ou mise à disposition des équipements, formation,
 * phase pilote, déploiement terrain, suivi, restitution.
 *
 * Le gabarit est un référentiel, pas un suivi : l'instancier sur une activité
 * copie ses étapes (libellé et ordre) dans des EtapeActivite autonomes. Une
 * modification ultérieure du gabarit ne remonte donc jamais dans les
 * activités déjà instanciées — sinon renommer une étape du référentiel
 * réécrirait l'historique de suivi de dizaines d'activités.
 */
#[ORM\Entity(repositoryClass: GabaritEtapesRepository::class)]
#[ORM\Table(name: 'gabarits_etapes')]
class GabaritEtapes
{
    use IdentifiantsTrait;
    use HorodatageTrait;
    use SuppressionLogiqueTrait;

    #[ORM\Column(length: 160)]
    private string $libelle;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Un gabarit retiré du catalogue reste attaché aux activités déjà instanciées. */
    #[ORM\Column]
    private bool $actif = true;

    /** @var Collection<int, EtapeGabarit> */
    #[ORM\OneToMany(targetEntity: EtapeGabarit::class, mappedBy: 'gabarit', cascade: ['persist'])]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $etapes;

    public function __construct(string $libelle)
    {
        $this->initialiserUuid();
        $this->libelle = $libelle;
        $this->etapes = new ArrayCollection();
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function estActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): void
    {
        $this->actif = $actif;
    }

    /** @return Collection<int, EtapeGabarit> */
    public function getEtapes(): Collection
    {
        return $this->etapes;
    }

    /** Réservé au constructeur de EtapeGabarit (côté propriétaire) — garde les deux côtés cohérents avant même un flush. */
    public function ajouterEtape(EtapeGabarit $etape): void
    {
        if (!$this->etapes->contains($etape)) {
            $this->etapes->add($etape);
        }
    }
}
