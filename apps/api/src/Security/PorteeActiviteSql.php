<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Condition SQL de la portée par structure sur les activités (§ 7.2) — une
 * seule règle pour le filtre Doctrine et les lecteurs SQL (arbre, budget,
 * exécution, alertes), qui divergeaient sinon.
 *
 * Une activité est dans le périmètre d'une structure si elle en est
 * responsable, ou si l'une de ses lignes (sous-activités) l'est : le PTBA
 * confie souvent des lignes d'une même activité à des directions
 * différentes (9 activités sur 26 au PTBA 2026), et le point focal d'une
 * ligne doit trouver l'activité pour la renseigner. Il voit l'activité
 * entière, mais ne modifie que ses lignes — cf. PermissionVoter.
 */
final class PorteeActiviteSql
{
    /**
     * @param string $alias alias SQL de la table `activites`
     * @param string $liste liste SQL des ids de structures autorisées
     *                      (placeholders ou valeurs échappées) ; elle
     *                      apparaît deux fois, ses paramètres doivent donc
     *                      être passés deux fois
     */
    public static function condition(string $alias, string $liste): string
    {
        return \sprintf(
            '(%1$s.structure_responsable_id IN (%2$s) OR EXISTS (SELECT 1 FROM sous_activites sa_portee'
            .' WHERE sa_portee.activite_id = %1$s.id AND sa_portee.supprime_le IS NULL'
            .' AND sa_portee.structure_responsable_id IN (%2$s)))',
            $alias,
            $liste,
        );
    }
}
