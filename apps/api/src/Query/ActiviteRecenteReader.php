<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * GET /projets/:uuid/activite-recente (§ 2.1 du brief) : fil des dernières
 * écritures du journal, rattachées au projet via l'entité modifiée. Pas de
 * filtre de portée — au contraire de l'arbre ou du budget, ce fil affiche
 * volontairement l'activité de toutes les structures (comme la maquette) ;
 * en contrepartie il ne restitue jamais les montants ni le contenu
 * avant/après du journal, seulement le type d'écriture et sa cible.
 */
final class ActiviteRecenteReader
{
    /** @var array<string, string> entite_type|action => libellé */
    private const LIBELLES = [
        'activite|creation' => 'Activité créée',
        'activite|modification' => 'Activité modifiée',
        'dotation|creation' => 'Dotation notifiée',
        'dotation|modification' => 'Dotation modifiée',
        'engagement|creation' => 'Engagement saisi',
        'engagement|modification' => 'Engagement mis à jour',
        'depense|creation' => 'Dépense saisie',
        'depense|modification' => 'Dépense mise à jour',
        'indicateur|creation' => 'Indicateur créé',
        'indicateur|modification' => 'Indicateur modifié',
        'indicateur_valeur|creation' => "Valeur d'indicateur saisie",
        'indicateur_valeur|modification' => "Valeur d'indicateur mise à jour",
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array{id: string, horodatage: string, type: string, action: string, libelleAction: string, code: ?string, libelleCible: ?string, utilisateur: ?string}> */
    public function lire(int $projetId, int $limite = 15): array
    {
        $sql = <<<'SQL'
            (SELECT j.id, j.cree_le, j.action, j.entite_type, a.code, a.libelle, u.prenom, u.nom
             FROM journal_activite j
             JOIN activites a ON a.id = j.entite_id
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id
             JOIN composantes c ON c.id = sc.composante_id
             LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
             WHERE j.entite_type = 'activite' AND c.projet_id = ?)
            UNION ALL
            (SELECT j.id, j.cree_le, j.action, j.entite_type, a.code, a.libelle, u.prenom, u.nom
             FROM journal_activite j
             JOIN dotations d ON d.id = j.entite_id
             LEFT JOIN sous_activites sad ON sad.id = d.sous_activite_id
             JOIN activites a ON a.id = COALESCE(d.activite_id, sad.activite_id)
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id
             JOIN composantes c ON c.id = sc.composante_id
             LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
             WHERE j.entite_type = 'dotation' AND c.projet_id = ?)
            UNION ALL
            (SELECT j.id, j.cree_le, j.action, j.entite_type, a.code, a.libelle, u.prenom, u.nom
             FROM journal_activite j
             JOIN engagements e ON e.id = j.entite_id
             LEFT JOIN sous_activites sae ON sae.id = e.sous_activite_id
             JOIN activites a ON a.id = COALESCE(e.activite_id, sae.activite_id)
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id
             JOIN composantes c ON c.id = sc.composante_id
             LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
             WHERE j.entite_type = 'engagement' AND c.projet_id = ?)
            UNION ALL
            (SELECT j.id, j.cree_le, j.action, j.entite_type, a.code, a.libelle, u.prenom, u.nom
             FROM journal_activite j
             JOIN depenses dp ON dp.id = j.entite_id
             JOIN engagements e ON e.id = dp.engagement_id
             LEFT JOIN sous_activites sadp ON sadp.id = e.sous_activite_id
             JOIN activites a ON a.id = COALESCE(e.activite_id, sadp.activite_id)
             JOIN sous_composantes sc ON sc.id = a.sous_composante_id
             JOIN composantes c ON c.id = sc.composante_id
             LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
             WHERE j.entite_type = 'depense' AND c.projet_id = ?)
            UNION ALL
            (SELECT j.id, j.cree_le, j.action, j.entite_type, i.code, i.libelle, u.prenom, u.nom
             FROM journal_activite j
             JOIN indicateurs i ON i.id = j.entite_id
             LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
             WHERE j.entite_type = 'indicateur' AND i.projet_id = ?)
            UNION ALL
            (SELECT j.id, j.cree_le, j.action, j.entite_type, i.code, i.libelle, u.prenom, u.nom
             FROM journal_activite j
             JOIN indicateur_valeurs iv ON iv.id = j.entite_id
             JOIN indicateurs i ON i.id = iv.indicateur_id
             LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
             WHERE j.entite_type = 'indicateur_valeur' AND i.projet_id = ?)
            -- L'identifiant départage les écritures d'une même seconde : un
            -- import en produit des dizaines, et sans lui l'ordre changeait
            -- d'un affichage à l'autre.
            ORDER BY cree_le DESC, id DESC
            LIMIT ?
            SQL;

        $lignes = $this->connection->fetchAllAssociative(
            $sql,
            [$projetId, $projetId, $projetId, $projetId, $projetId, $projetId, $limite],
            [
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::INTEGER,
            ],
        );

        return array_map($this->normaliserLigne(...), $lignes);
    }

    /**
     * @param array<string, mixed> $ligne
     *
     * @return array{id: string, horodatage: string, type: string, action: string, libelleAction: string, code: ?string, libelleCible: ?string, utilisateur: ?string}
     */
    private function normaliserLigne(array $ligne): array
    {
        $cle = $ligne['entite_type'].'|'.$ligne['action'];
        $utilisateur = null !== $ligne['prenom'] ? trim($ligne['prenom'].' '.$ligne['nom']) : null;

        return [
            // Seul identifiant unique d'un événement : horodatage, type et
            // code se répètent quand plusieurs dotations d'une même activité
            // changent dans la même seconde, ce que fait l'import du PTBA.
            'id' => (string) $ligne['id'],
            'horodatage' => (new \DateTimeImmutable($ligne['cree_le']))->format(\DateTimeInterface::ATOM),
            'type' => $ligne['entite_type'],
            'action' => $ligne['action'],
            'libelleAction' => self::LIBELLES[$cle] ?? $cle,
            'code' => $ligne['code'],
            'libelleCible' => $ligne['libelle'],
            'utilisateur' => $utilisateur,
        ];
    }
}
