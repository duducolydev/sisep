<?php

declare(strict_types=1);

namespace App\Query;

use App\Entity\Utilisateur;
use App\Security\PermissionsResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Tableau de bord d'exécution physique et financière (retours des RSE,
 * octobre 2026), filtrable par période (année, trimestre, mois) et par
 * composante ou activité.
 *
 * Exécution physique — « nombre d'activités réalisées sur le nombre total ».
 * L'unité comptée est la ligne du PTBA (sous-activité) ; une activité sans
 * ligne compte pour une unité. Une unité est réalisée quand toutes ses
 * étapes sont à 100 %, à la date de leur dernière mise à jour : c'est cette
 * date qui la fait entrer dans une période. Une unité sans étape compte dans
 * le total, jamais dans les réalisées.
 *
 * Exécution financière — budget = dotations de l'exercice ; engagé et
 * dépensé = écritures validées, cumulées depuis le début de l'exercice
 * jusqu'à la fin de la période (taux « à fin de période »), avec en regard
 * le montant de la période seule. Mêmes règles que les vues v_execution_*
 * (écritures validées, non supprimées ; activité = ses propres écritures
 * plus celles de ses lignes), recalculées ici parce que ces vues ignorent
 * les dates.
 *
 * Portée : comme l'arbre, seules les activités que l'utilisateur peut
 * consulter entrent dans le calcul. Grandeurs dérivées jamais stockées ;
 * division par zéro → null.
 */
final class ExecutionSyntheseReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function lire(
        int $projetId,
        int $exerciceId,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        ?string $composanteUuid,
        ?string $activiteUuid,
    ): array {
        $activites = $this->activites($projetId);
        $composantes = $this->connection->fetchAllAssociative(
            'SELECT id, uuid, code, libelle FROM composantes WHERE projet_id = ? AND supprime_le IS NULL ORDER BY ordre, code',
            [$projetId],
        );

        // Options des filtres : tout le périmètre consultable, avant filtrage.
        $options = [
            'composantes' => array_map(static fn (array $c) => ['uuid' => $c['uuid'], 'code' => $c['code'], 'libelle' => $c['libelle']], $composantes),
            'activites' => array_map(static fn (array $a) => [
                'uuid' => $a['uuid'],
                'code' => $a['code'],
                'libelle' => $a['libelle'],
                'composanteUuid' => $a['composante_uuid'],
            ], $activites),
        ];

        $niveau = 'composante';
        if (null !== $activiteUuid) {
            $activites = array_values(array_filter($activites, static fn (array $a) => $a['uuid'] === $activiteUuid));
            $niveau = 'ligne';
        } elseif (null !== $composanteUuid) {
            $activites = array_values(array_filter($activites, static fn (array $a) => $a['composante_uuid'] === $composanteUuid));
            $niveau = 'activite';
        }

        $unites = $this->unites($activites, $exerciceId, $debut, $fin);

        // Regroupement au niveau du dessous de la sélection.
        $groupes = [];
        foreach ($unites as $unite) {
            [$cle, $code, $libelle] = match ($niveau) {
                'composante' => [$unite['composante_uuid'], $unite['composante_code'], $unite['composante_libelle']],
                'activite' => [$unite['activite_uuid'], $unite['activite_code'], $unite['activite_libelle']],
                default => [$unite['uuid'], $unite['code'], $unite['libelle']],
            };
            $groupes[$cle] ??= ['uuid' => $cle, 'code' => $code, 'libelle' => $libelle, 'unites' => []];
            $groupes[$cle]['unites'][] = $unite;
        }

        return [
            'periode' => ['debut' => $debut->format('Y-m-d'), 'fin' => $fin->format('Y-m-d')],
            'niveau' => $niveau,
            'totaux' => $this->agreger($unites),
            'lignes' => array_map(fn (array $g) => [
                'uuid' => $g['uuid'],
                'code' => $g['code'],
                'libelle' => $g['libelle'],
                ...$this->agreger($g['unites']),
            ], array_values($groupes)),
            'options' => $options,
        ];
    }

    /**
     * @param list<array<string, mixed>> $unites
     *
     * @return array<string, int|float|null>
     */
    private function agreger(array $unites): array
    {
        $total = 0;
        $realisees = 0;
        $progressions = [];
        $budget = $engage = $depense = $engagePeriode = $depensePeriode = 0;
        foreach ($unites as $u) {
            if ($u['physique']) {
                ++$total;
                $realisees += $u['realisee'] ? 1 : 0;
                if (null !== $u['progression']) {
                    $progressions[] = $u['progression'];
                }
            }
            $budget += $u['budget'];
            $engage += $u['engage'];
            $depense += $u['depense'];
            $engagePeriode += $u['engagePeriode'];
            $depensePeriode += $u['depensePeriode'];
        }

        return [
            'unites' => $total,
            'realisees' => $realisees,
            'tauxPhysique' => 0 === $total ? null : $realisees / $total,
            'avancementMoyen' => [] === $progressions ? null : array_sum($progressions) / \count($progressions),
            'budget' => $budget,
            'engage' => $engage,
            'depense' => $depense,
            'engagePeriode' => $engagePeriode,
            'depensePeriode' => $depensePeriode,
            'tauxEngagement' => 0 === $budget ? null : $engage / $budget,
            'tauxExecution' => 0 === $budget ? null : $depense / $budget,
            'tauxDecaissement' => 0 === $engage ? null : $depense / $engage,
        ];
    }

    /**
     * Unités physiques (lignes, ou activité sans ligne) avec leurs montants.
     * Les écritures portées directement par une activité qui a des lignes
     * forment une unité financière de plus, sans poids physique.
     *
     * @param list<array<string, mixed>> $activites
     *
     * @return list<array<string, mixed>>
     */
    private function unites(array $activites, int $exerciceId, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        if ([] === $activites) {
            return [];
        }
        $activiteIds = array_map(static fn (array $a) => (int) $a['id'], $activites);
        $types = [ArrayParameterType::INTEGER];

        $lignesParActivite = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, uuid, activite_id, libelle FROM sous_activites WHERE activite_id IN (?) AND supprime_le IS NULL ORDER BY ordre, id',
            [$activiteIds],
            $types,
        ) as $ligne) {
            $lignesParActivite[(int) $ligne['activite_id']][] = $ligne;
        }

        // Étapes par bloc : (activité, ligne) — ligne nulle = étapes propres.
        $etapes = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT e.activite_id, e.sous_activite_id, COUNT(*) AS total, SUM(e.avancement = 100) AS achevees,
                    AVG(e.avancement) AS moyenne, MAX(e.date_mise_a_jour) AS derniere_mise_a_jour
             FROM etapes_activite e
             WHERE e.activite_id IN (?) AND e.supprime_le IS NULL
             GROUP BY e.activite_id, e.sous_activite_id',
            [$activiteIds],
            $types,
        ) as $bloc) {
            $etapes[(int) $bloc['activite_id'].':'.($bloc['sous_activite_id'] ?? '')] = $bloc;
        }

        $finTexte = $fin->format('Y-m-d');
        $debutTexte = $debut->format('Y-m-d');
        $montants = $this->montants($activiteIds, $exerciceId, $debutTexte, $finTexte);

        $unites = [];
        foreach ($activites as $a) {
            $id = (int) $a['id'];
            $contexte = [
                'composante_uuid' => $a['composante_uuid'],
                'composante_code' => $a['composante_code'],
                'composante_libelle' => $a['composante_libelle'],
                'activite_uuid' => $a['uuid'],
                'activite_code' => $a['code'],
                'activite_libelle' => $a['libelle'],
            ];
            $lignes = $lignesParActivite[$id] ?? [];

            if ([] === $lignes) {
                $unites[] = [
                    ...$contexte,
                    'uuid' => $a['uuid'],
                    'code' => $a['code'],
                    'libelle' => $a['libelle'],
                    ...$this->physique($etapes[$id.':'] ?? null, $finTexte),
                    ...($montants['a:'.$id] ?? $this->montantsVides()),
                ];

                continue;
            }

            foreach ($lignes as $ligne) {
                $unites[] = [
                    ...$contexte,
                    'uuid' => $ligne['uuid'],
                    'code' => $a['code'],
                    'libelle' => $ligne['libelle'],
                    ...$this->physique($etapes[$id.':'.$ligne['id']] ?? null, $finTexte),
                    ...($montants['l:'.$ligne['id']] ?? $this->montantsVides()),
                ];
            }

            // Écritures posées sur l'activité elle-même, hors lignes.
            $propres = $montants['a:'.$id] ?? null;
            if (null !== $propres && array_sum($propres) > 0) {
                $unites[] = [
                    ...$contexte,
                    'uuid' => $a['uuid'],
                    'code' => $a['code'],
                    'libelle' => $a['libelle'].' (hors lignes)',
                    'realisee' => false,
                    'progression' => null,
                    'physique' => false,
                    ...$propres,
                ];
            }
        }

        return $unites;
    }

    /**
     * @param array<string, mixed>|null $bloc
     *
     * @return array{realisee: bool, progression: float|null, physique: bool}
     */
    private function physique(?array $bloc, string $fin): array
    {
        if (null === $bloc || 0 === (int) $bloc['total']) {
            return ['realisee' => false, 'progression' => null, 'physique' => true];
        }

        $toutesAchevees = (int) $bloc['achevees'] === (int) $bloc['total'];
        $derniere = $bloc['derniere_mise_a_jour'];

        return [
            'realisee' => $toutesAchevees && (null === $derniere || $derniere <= $fin),
            'progression' => (float) $bloc['moyenne'] / 100,
            'physique' => true,
        ];
    }

    /** @return array{budget: int, engage: int, depense: int, engagePeriode: int, depensePeriode: int} */
    private function montantsVides(): array
    {
        return ['budget' => 0, 'engage' => 0, 'depense' => 0, 'engagePeriode' => 0, 'depensePeriode' => 0];
    }

    /**
     * Montants par cible : 'a:{id}' pour les écritures posées sur l'activité
     * elle-même, 'l:{id}' pour celles d'une ligne.
     *
     * @param list<int> $activiteIds
     *
     * @return array<string, array{budget: int, engage: int, depense: int, engagePeriode: int, depensePeriode: int}>
     */
    private function montants(array $activiteIds, int $exerciceId, string $debut, string $fin): array
    {
        $types = [\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::INTEGER, ArrayParameterType::INTEGER];
        $resultat = [];
        $cible = static fn (array $l): string => null !== $l['sous_activite_id'] ? 'l:'.$l['sous_activite_id'] : 'a:'.$l['activite_id'];
        $ajouter = function (string $cle, string $champ, int $montant) use (&$resultat): void {
            $resultat[$cle] ??= $this->montantsVides();
            $resultat[$cle][$champ] += $montant;
        };

        foreach ($this->connection->fetchAllAssociative(
            'SELECT d.activite_id, d.sous_activite_id, SUM(d.montant) AS montant
             FROM dotations d
             LEFT JOIN sous_activites sa ON sa.id = d.sous_activite_id
             WHERE d.exercice_id = ? AND (d.activite_id IN (?) OR sa.activite_id IN (?))
             GROUP BY d.activite_id, d.sous_activite_id',
            [$exerciceId, $activiteIds, $activiteIds],
            $types,
        ) as $l) {
            $ajouter($cible($l), 'budget', (int) $l['montant']);
        }

        foreach ($this->connection->fetchAllAssociative(
            'SELECT e.activite_id, e.sous_activite_id,
                    SUM(CASE WHEN e.date_engagement <= ? THEN e.montant ELSE 0 END) AS cumul,
                    SUM(CASE WHEN e.date_engagement BETWEEN ? AND ? THEN e.montant ELSE 0 END) AS periode
             FROM engagements e
             LEFT JOIN sous_activites sa ON sa.id = e.sous_activite_id
             WHERE e.exercice_id = ? AND e.statut = \'valide\' AND e.supprime_le IS NULL
               AND (e.activite_id IN (?) OR sa.activite_id IN (?))
             GROUP BY e.activite_id, e.sous_activite_id',
            [$fin, $debut, $fin, $exerciceId, $activiteIds, $activiteIds],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, ...$types],
        ) as $l) {
            $ajouter($cible($l), 'engage', (int) $l['cumul']);
            $ajouter($cible($l), 'engagePeriode', (int) $l['periode']);
        }

        foreach ($this->connection->fetchAllAssociative(
            'SELECT e.activite_id, e.sous_activite_id,
                    SUM(CASE WHEN dp.date_depense <= ? THEN dp.montant ELSE 0 END) AS cumul,
                    SUM(CASE WHEN dp.date_depense BETWEEN ? AND ? THEN dp.montant ELSE 0 END) AS periode
             FROM depenses dp
             JOIN engagements e ON e.id = dp.engagement_id
             LEFT JOIN sous_activites sa ON sa.id = e.sous_activite_id
             WHERE e.exercice_id = ? AND dp.statut = \'valide\' AND dp.supprime_le IS NULL
               AND (e.activite_id IN (?) OR sa.activite_id IN (?))
             GROUP BY e.activite_id, e.sous_activite_id',
            [$fin, $debut, $fin, $exerciceId, $activiteIds, $activiteIds],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, ...$types],
        ) as $l) {
            $ajouter($cible($l), 'depense', (int) $l['cumul']);
            $ajouter($cible($l), 'depensePeriode', (int) $l['periode']);
        }

        return $resultat;
    }

    /**
     * Activités consultables du projet, avec leur composante.
     *
     * @return list<array<string, mixed>>
     */
    private function activites(int $projetId): array
    {
        $utilisateur = $this->security->getUser();
        $permissions = $utilisateur instanceof Utilisateur ? $this->permissionsResolver->pour($utilisateur) : null;

        $condition = '';
        $parametres = [$projetId];
        $types = [\Doctrine\DBAL\ParameterType::INTEGER];
        if (null !== $permissions && !$permissions->estGlobale('activites.consulter')) {
            $structureIds = $permissions->structuresAutorisees('activites.consulter');
            if ([] === $structureIds) {
                return [];
            }
            $condition = ' AND a.structure_responsable_id IN (?)';
            $parametres[] = $structureIds;
            $types[] = ArrayParameterType::INTEGER;
        }

        return $this->connection->fetchAllAssociative(
            'SELECT a.id, a.uuid, a.code, a.libelle,
                    c.uuid AS composante_uuid, c.code AS composante_code, c.libelle AS composante_libelle
             FROM activites a
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id AND sc.supprime_le IS NULL
             JOIN composantes c ON c.id = sc.composante_id AND c.supprime_le IS NULL
             WHERE c.projet_id = ? AND a.supprime_le IS NULL'.$condition.'
             ORDER BY c.ordre, c.code, sc.ordre, sc.code, a.ordre, a.code',
            $parametres,
            $types,
        );
    }
}
