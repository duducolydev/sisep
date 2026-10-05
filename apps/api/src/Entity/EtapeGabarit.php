<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiantsTrait;
use App\Repository\EtapeGabaritRepository;
use Doctrine\ORM\Mapping as ORM;

/** Une étape du référentiel, à sa place dans le gabarit — copiée, jamais référencée, au moment d'instancier le gabarit sur une activité (cf. GabaritEtapes). */
#[ORM\Entity(repositoryClass: EtapeGabaritRepository::class)]
#[ORM\Table(name: 'etapes_gabarit')]
class EtapeGabarit
{
    use IdentifiantsTrait;

    #[ORM\ManyToOne(targetEntity: GabaritEtapes::class, inversedBy: 'etapes')]
    #[ORM\JoinColumn(nullable: false)]
    private GabaritEtapes $gabarit;

    #[ORM\Column(length: 160)]
    private string $libelle;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $ordre;

    public function __construct(GabaritEtapes $gabarit, string $libelle, int $ordre)
    {
        $this->initialiserUuid();
        $this->gabarit = $gabarit;
        $this->libelle = $libelle;
        $this->ordre = $ordre;
        $gabarit->ajouterEtape($this);
    }

    public function getGabarit(): GabaritEtapes
    {
        return $this->gabarit;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): void
    {
        $this->libelle = $libelle;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): void
    {
        $this->ordre = $ordre;
    }
}
