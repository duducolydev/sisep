<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Structure;

/**
 * Entités d'une activité du PTBA (l'activité, ses lignes, leurs étapes) :
 * en consultation, la portée s'étend à toute structure concernée par
 * l'activité — la sienne et celles de ses lignes. Le point focal d'une ligne
 * confiée à sa direction ouvre ainsi l'activité qui la contient ; il ne
 * modifie toujours que ce qui relève de sa structure, la portée en écriture
 * restant celle de getStructurePourPortee(). Même règle que le filtre SQL,
 * cf. PorteeActiviteSql.
 */
interface PorteeEnConsultationInterface extends PorteeParStructureInterface
{
    /** @return list<Structure> */
    public function getStructuresConcernees(): array;
}
