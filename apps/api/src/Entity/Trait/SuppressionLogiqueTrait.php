<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Suppression logique (§ 3.3 du brief). La suppression physique est interdite
 * sur toute entité budgétaire ou historique — ce trait ne définit pas de
 * méthode remove() qui appellerait EntityManager::remove(), volontairement.
 */
trait SuppressionLogiqueTrait
{
    #[ORM\Column(type: 'datetime_immutable', columnDefinition: 'DATETIME', nullable: true)]
    private ?\DateTimeImmutable $supprimeLe = null;

    public function getSupprimeLe(): ?\DateTimeImmutable
    {
        return $this->supprimeLe;
    }

    public function estSupprime(): bool
    {
        return null !== $this->supprimeLe;
    }

    public function supprimer(): void
    {
        $this->supprimeLe = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function restaurer(): void
    {
        $this->supprimeLe = null;
    }
}
