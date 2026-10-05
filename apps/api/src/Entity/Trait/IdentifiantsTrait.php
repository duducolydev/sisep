<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Clé primaire interne BIGINT AUTO_INCREMENT, jamais exposée, plus un uuid
 * exposé dans les URL et l'API (§ 3.3 du brief).
 */
trait IdentifiantsTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    private function initialiserUuid(): void
    {
        $this->uuid = Uuid::v4();
    }
}
