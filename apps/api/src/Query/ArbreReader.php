<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\Utilisateur;
use App\Security\PermissionsResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * GET /projets/:uuid/arbre (§ 6.2 du brief). Contourne volontairement
 * l'hydratation Doctrine ORM — décision actée au § 4.7 : l'arbre complet est
 * de la pure agrégation en lecture, DBAL direct est plus simple et plus
 * rapide qu'un graphe d'entités.
 *
 * Portée (§ 7.2) : les composantes et sous-composantes restent visibles de
 * tous (décision actée avec l'ANSD — un point focal doit pouvoir situer ses
 * activités dans l'ensemble du projet). Seules les lignes d'activités sont
 * filtrées par structure. Les totaux de composante/sous-composante viennent
 * des vues v_execution_* (§ 4.7), qui ne connaissent pas la portée : ils
 * reflètent l'exécution réelle du projet, pas seulement ce qu'un utilisateur
 * scopé peut modifier — un choix de transparence, pas une fuite : aucune
 * activité individuelle hors périmètre n'est jamais listée nommément.
 *
 * Mis en cache côté serveur (§ 6.2 : "le mettre en cache avec invalidation
 * sur toute écriture budgétaire", cf. ArbreCacheInvalidationListener). La
 * clé inclut la portée de l'utilisateur — jamais un cache partagé entre deux
 * portées différentes, ce qui fuiterait ou masquerait des activités.
 */
final class ArbreReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
        private readonly TagAwareCacheInterface $arbreCache,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function lire(int $projetId, int $exerciceId, int $niveaux): array
    {
        $cle = \sprintf('arbre.%d.%d.%d.%s', $projetId, $exerciceId, $niveaux, $this->signaturePortee());

        return $this->arbreCache->get($cle, function (ItemInterface $item) use ($projetId, $exerciceId, $niveaux) {
            $item->tag('arbre');
            $item->expiresAfter(3600);

            return $this->construire($projetId, $exerciceId, $niveaux);
        });
    }

    private function signaturePortee(): string
    {
        $utilisateur = $this->utilisateurCourantOuNull();
        $permissions = null !== $utilisateur ? $this->permissionsResolver->pour($utilisateur) : null;

        if (null === $permissions || $permissions->estGlobale('activites.consulter')) {
            return 'globale';
        }

        $structureIds = $permissions->structuresAutorisees('activites.consulter');
        sort($structureIds);

        return 'structures-'.implode('-', $structureIds);
    }

    /** @return array<int, array<string, mixed>> */
    private function construire(int $projetId, int $exerciceId, int $niveaux): array
    {
        $composantes = $this->connection->fetchAllAssociative(
            'SELECT id, uuid, code, libelle, ordre FROM composantes WHERE projet_id = ? AND supprime_le IS NULL ORDER BY ordre, code',
            [$projetId],
        );
        if ([] === $composantes) {
            return [];
        }
        $composanteIds = array_column($composantes, 'id');

        $totauxComposantes = $this->indexerParCle(
            $this->connection->fetchAllAssociative(
                'SELECT composante_id, notifie, engage, depense FROM v_execution_composante WHERE exercice_id = ? AND composante_id IN ('.$this->placeholders($composanteIds).')',
                [$exerciceId, ...$composanteIds],
            ),
            'composante_id',
        );

        $sousComposantes = $this->connection->fetchAllAssociative(
            'SELECT id, uuid, composante_id, code, libelle, ordre FROM sous_composantes WHERE composante_id IN ('.$this->placeholders($composanteIds).') AND supprime_le IS NULL ORDER BY ordre, code',
            $composanteIds,
        );
        $sousComposanteIds = array_column($sousComposantes, 'id');

        $totauxSousComposantes = [] === $sousComposanteIds ? [] : $this->indexerParCle(
            $this->connection->fetchAllAssociative(
                'SELECT sous_composante_id, notifie, engage, depense FROM v_execution_sous_composante WHERE exercice_id = ? AND sous_composante_id IN ('.$this->placeholders($sousComposanteIds).')',
                [$exerciceId, ...$sousComposanteIds],
            ),
            'sous_composante_id',
        );

        $activites = [] === $sousComposanteIds ? [] : $this->recupererActivites($sousComposanteIds, $exerciceId);
        $activiteIds = array_column($activites, 'id');

        $sousActivitesParActivite = [];
        if ($niveaux >= 4 && [] !== $activiteIds) {
            $sousActivitesParActivite = $this->regrouperParCle(
                $this->connection->fetchAllAssociative(
                    'SELECT sa.uuid, sa.activite_id, sa.libelle, sa.statut, sa.echeance, v.notifie, v.engage, v.depense
                     FROM sous_activites sa
                     LEFT JOIN v_execution_sous_activite v ON v.sous_activite_id = sa.id AND v.exercice_id = ?
                     WHERE sa.activite_id IN ('.$this->placeholders($activiteIds).') AND sa.supprime_le IS NULL
                     ORDER BY sa.ordre',
                    [$exerciceId, ...$activiteIds],
                ),
                'activite_id',
            );
        }

        $activitesParSousComposante = $this->regrouperParCle($activites, 'sous_composante_id');
        $sousComposantesParComposante = $this->regrouperParCle($sousComposantes, 'composante_id');

        return array_map(
            function (array $c) use ($sousComposantesParComposante, $totauxComposantes, $activitesParSousComposante, $totauxSousComposantes, $sousActivitesParActivite, $niveaux): array {
                $noeud = [
                    'uuid' => $c['uuid'],
                    'code' => $c['code'],
                    'libelle' => $c['libelle'],
                    ...$this->montants($totauxComposantes[$c['id']] ?? null),
                ];

                if ($niveaux >= 2) {
                    $noeud['sousComposantes'] = array_map(
                        function (array $sc) use ($totauxSousComposantes, $activitesParSousComposante, $sousActivitesParActivite, $niveaux): array {
                            $noeudSc = [
                                'uuid' => $sc['uuid'],
                                'code' => $sc['code'],
                                'libelle' => $sc['libelle'],
                                ...$this->montants($totauxSousComposantes[$sc['id']] ?? null),
                            ];

                            if ($niveaux >= 3) {
                                $noeudSc['activites'] = array_map(
                                    fn (array $a) => $this->noeudActivite($a, $sousActivitesParActivite[$a['id']] ?? [], $niveaux),
                                    $activitesParSousComposante[$sc['id']] ?? [],
                                );
                            }

                            return $noeudSc;
                        },
                        $sousComposantesParComposante[$c['id']] ?? [],
                    );
                }

                return $noeud;
            },
            $composantes,
        );
    }

    /**
     * @param array<string, mixed>       $a
     * @param list<array<string, mixed>> $sousActivites
     *
     * @return array<string, mixed>
     */
    private function noeudActivite(array $a, array $sousActivites, int $niveaux): array
    {
        $noeud = [
            'uuid' => $a['uuid'],
            'code' => $a['code'],
            'libelle' => $a['libelle'],
            'statut' => $a['statut'],
            'structureResponsable' => ['uuid' => $a['structure_uuid'], 'sigle' => $a['structure_sigle']],
            // Ne dépend plus des sous-activités : une activité peut être
            // structurée en étapes sans porter la moindre ligne budgétaire,
            // donc l'avancement est servi dès le niveau 3.
            'avancement' => null !== $a['progression'] ? (int) round((float) $a['progression']) : null,
            ...$this->montants($a),
        ];

        if ($niveaux >= 4) {
            // La sous-activité est devenue une maille purement budgétaire
            // (§ 6 du CR du 22/09/2026) : plus d'avancement exposé ici, la
            // colonne sous_activites.avancement n'étant plus alimentée.
            $noeud['sousActivites'] = array_map(fn (array $sa) => [
                'uuid' => $sa['uuid'],
                'libelle' => $sa['libelle'],
                'statut' => $sa['statut'],
                'echeance' => $sa['echeance'],
                ...$this->montants($sa),
            ], $sousActivites);
        }

        return $noeud;
    }

    /**
     * @param list<int> $sousComposanteIds
     *
     * @return list<array<string, mixed>>
     */
    private function recupererActivites(array $sousComposanteIds, int $exerciceId): array
    {
        $utilisateur = $this->utilisateurCourantOuNull();
        $permissions = null !== $utilisateur ? $this->permissionsResolver->pour($utilisateur) : null;

        $conditionPortee = '';
        $parametresPortee = [];
        if (null !== $permissions && !$permissions->estGlobale('activites.consulter')) {
            $structureIds = $permissions->structuresAutorisees('activites.consulter');
            if ([] === $structureIds) {
                return [];
            }
            $conditionPortee = ' AND a.structure_responsable_id IN ('.$this->placeholders($structureIds).')';
            $parametresPortee = $structureIds;
        }

        // `progression` est la moyenne de l'avancement des étapes actives,
        // en SQL plutôt qu'en PHP : ProgressionActiviteCalculator dit la même
        // chose sur un objet Activite, mais l'arbre lit des lignes brutes et
        // ne doit pas hydrater une entité par activité. Aucune étape →
        // AVG() renvoie NULL, jamais 0 (même règle que le calculateur).
        $sql = 'SELECT a.id, a.uuid, a.sous_composante_id, a.code, a.libelle, a.statut, a.ordre,
                        s.uuid AS structure_uuid, s.sigle AS structure_sigle,
                        v.notifie, v.engage, v.depense,
                        (SELECT AVG(e.avancement) FROM etapes_activite e
                          WHERE e.activite_id = a.id AND e.supprime_le IS NULL) AS progression
                 FROM activites a
                 JOIN structures s ON s.id = a.structure_responsable_id
                 LEFT JOIN v_execution_activite v ON v.activite_id = a.id AND v.exercice_id = ?
                 WHERE a.sous_composante_id IN ('.$this->placeholders($sousComposanteIds).')
                   AND a.supprime_le IS NULL'.$conditionPortee.'
                 ORDER BY a.ordre, a.code';

        return $this->connection->fetchAllAssociative($sql, [$exerciceId, ...$sousComposanteIds, ...$parametresPortee]);
    }

    private function utilisateurCourantOuNull(): ?Utilisateur
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }

    /**
     * @param array<string, mixed>|null $ligne
     *
     * @return array{notifie: int|null, engage: int|null, depense: int|null}
     */
    private function montants(?array $ligne): array
    {
        return [
            'notifie' => null !== $ligne && null !== $ligne['notifie'] ? (int) $ligne['notifie'] : null,
            'engage' => null !== $ligne && null !== $ligne['engage'] ? (int) $ligne['engage'] : null,
            'depense' => null !== $ligne && null !== $ligne['depense'] ? (int) $ligne['depense'] : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function indexerParCle(array $lignes, string $cle): array
    {
        $index = [];
        foreach ($lignes as $ligne) {
            $index[$ligne[$cle]] = $ligne;
        }

        return $index;
    }

    /**
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<int|string, list<array<string, mixed>>>
     */
    private function regrouperParCle(array $lignes, string $cle): array
    {
        $groupes = [];
        foreach ($lignes as $ligne) {
            $groupes[$ligne[$cle]][] = $ligne;
        }

        return $groupes;
    }

    /** @param list<int> $valeurs */
    private function placeholders(array $valeurs): string
    {
        return implode(',', array_fill(0, \count($valeurs), '?'));
    }
}
