<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * cree_le / modifie_le en DATETIME(3) UTC (§ 3.3 du brief). L'affichage se
 * fait en Africa/Dakar au niveau de la présentation, jamais ici.
 */
trait HorodatageTrait
{
    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME(3)')]
    private \DateTimeImmutable $modifieLe;

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    #[ORM\PrePersist]
    public function horodaterCreation(): void
    {
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->creeLe = $maintenant;
        $this->modifieLe = $maintenant;
    }

    #[ORM\PreUpdate]
    public function horodaterModification(): void
    {
        $this->modifieLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
