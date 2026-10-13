<?php

declare(strict_types=1);

namespace App\Amorcage;

use App\Entity\Parametre;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Référentiel applicatif : permissions, rôles, paramètres et premier compte
 * d'administration.
 *
 * C'est ce qu'une base doit contenir pour que l'application fonctionne, par
 * opposition aux données du projet (PTBA, structures, personnel) et au jeu
 * de démonstration. Les trois sont distincts, et seul celui-ci est
 * indispensable partout.
 *
 * Extrait de la commande de démonstration pour que la mise en production
 * n'ait pas à la lancer : le catalogue des droits est le même des deux
 * côtés, et deux copies auraient fini par diverger — une permission ajoutée
 * d'un côté, absente de l'autre, donne un rôle qui ne peut rien faire.
 */
final class ReferentielApplicatif
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
    }

    /** @return array<string, Permission> code => Permission */
    public function semerPermissions(): array
    {
        $definitions = [
            ['activites.consulter', 'activites', 'Consulter les activités'],
            ['activites.creer', 'activites', 'Créer une activité'],
            ['activites.modifier', 'activites', 'Modifier une activité'],
            ['activites.supprimer', 'activites', 'Supprimer une activité'],
            ['activites.valider', 'activites', "Valider la clôture d'une activité"],
            ['budget.engagement.saisir', 'budget', 'Saisir un engagement'],
            ['budget.engagement.valider', 'budget', 'Valider un engagement'],
            ['budget.depense.saisir', 'budget', 'Saisir une dépense'],
            ['budget.depense.valider', 'budget', 'Valider une dépense'],
            ['indicateurs.consulter', 'indicateurs', 'Consulter le catalogue des indicateurs de performance'],
            ['indicateurs.definir', 'indicateurs', 'Définir un indicateur'],
            ['indicateurs.saisir', 'indicateurs', "Saisir une valeur d'indicateur"],
            ['indicateurs.valider', 'indicateurs', "Valider une valeur d'indicateur"],
            ['indicateurs.inviter', 'indicateurs', 'Inviter un sectoriel à saisir un indicateur'],
            ['structures.gerer', 'structures', 'Gérer les structures'],
            ['points_focaux.gerer', 'points_focaux', 'Gérer les points focaux'],
            ['admin.roles', 'admin', 'Gérer les rôles et permissions'],
            ['admin.parametres', 'admin', 'Gérer les paramètres'],
            ['admin.utilisateurs', 'admin', 'Gérer les comptes utilisateurs'],
            ['journal.consulter', 'journal', "Consulter le journal d'activité"],
            ['documents.gerer', 'documents', 'Déposer et retirer des documents de la bibliothèque'],
            ['exports.generer', 'exports', 'Générer un export'],
            ['initiatives.gerer', 'initiatives', 'Gérer le suivi des projets'],
            ['rh.consulter', 'rh', 'Consulter le suivi des ressources humaines'],
            ['rh.gerer', 'rh', 'Gérer le personnel, les contrats et les effectifs'],
        ];

        $permissions = [];
        foreach ($definitions as [$code, $groupe, $libelle]) {
            $permission = new Permission($code, $groupe, $libelle);
            $this->em->persist($permission);
            $permissions[$code] = $permission;
        }

        return $permissions;
    }

    /**
     * @param array<string, Permission> $permissions
     *
     * @return array<string, Role> code => Role
     */
    public function semerRoles(array $permissions): array
    {
        $accorder = static function (Role $role, array $permissions, array $codes): void {
            foreach ($codes as $code) {
                $role->accorder($permissions[$code]);
            }
        };

        $coordonnateur = new Role('coordonnateur', 'Coordonnateur', true);
        $accorder($coordonnateur, $permissions, [
            'activites.consulter', 'activites.creer', 'activites.modifier', 'activites.supprimer', 'activites.valider',
            'budget.engagement.valider', 'budget.depense.valider', 'indicateurs.consulter', 'indicateurs.valider',
            'indicateurs.inviter', 'structures.gerer', 'points_focaux.gerer', 'admin.roles', 'admin.parametres',
            'admin.utilisateurs', 'journal.consulter', 'exports.generer', 'initiatives.gerer',
            'rh.consulter', 'rh.gerer', 'documents.gerer',
        ]);

        // Dérogation assumée à la séparation saisie/validation (§ 7.2),
        // décidée après la réunion du 22/09/2026 : le responsable
        // suivi-évaluation tient le budget de bout en bout dans les faits,
        // l'UGP n'ayant pas deux agents distincts à y consacrer. Elle est
        // bornée et traçable — journal_activite retient qui a saisi, modifié
        // et validé chaque dotation, engagement et dépense, avec l'état
        // avant et après, et l'écran du budget expose ce fil. Aucun autre
        // rôle opérationnel ne cumule les deux : le gestionnaire budgétaire
        // saisit sans valider, le coordonnateur valide sans saisir.
        $suiviEvaluation = new Role('responsable_suivi_evaluation', 'Responsable suivi-évaluation', true);
        $accorder($suiviEvaluation, $permissions, [
            'activites.consulter', 'activites.creer', 'activites.modifier', 'activites.valider',
            'budget.engagement.saisir', 'budget.engagement.valider',
            'budget.depense.saisir', 'budget.depense.valider',
            'indicateurs.consulter', 'indicateurs.definir', 'indicateurs.saisir', 'indicateurs.inviter',
            'journal.consulter', 'exports.generer', 'initiatives.gerer', 'admin.parametres',
            'rh.consulter', 'rh.gerer', 'documents.gerer',
        ]);

        $gestionnaireBudgetaire = new Role('gestionnaire_budgetaire', 'Gestionnaire budgétaire', true);
        $accorder($gestionnaireBudgetaire, $permissions, [
            'activites.consulter', 'budget.engagement.saisir', 'budget.depense.saisir',
            'indicateurs.consulter', 'exports.generer',
        ]);

        // Séparation saisir/valider (§ 7.2) : le gestionnaire budgétaire ne
        // détient jamais budget.*.valider, le coordonnateur ne détient
        // jamais budget.*.saisir. Le module Indicateurs de performance est
        // réservé à l'équipe PHASAOC (indicateurs.consulter en portée
        // globale, absent ici) : un point focal de structure renseigne
        // seulement l'avancement des activités dont sa structure est
        // responsable, jamais la saisie ni la validation des indicateurs
        // (portée-aware pour activites.modifier, cf.
        // SousActivite::getStructurePourPortee()).
        $pointFocalStructure = new Role('point_focal_structure', 'Point focal de structure', true);
        $accorder($pointFocalStructure, $permissions, [
            'activites.consulter', 'activites.modifier', 'points_focaux.gerer',
        ]);

        // Rôle d'administration de la plateforme, pas un rôle opérationnel :
        // il détient TOUTES les permissions, donc budget.*.saisir ET
        // budget.*.valider à la fois. C'est une dérogation assumée à la
        // séparation saisie/validation du § 7.2, au même titre que
        // celle accordée au responsable suivi-évaluation : elle ne doit jamais
        // être attribuée à quelqu'un qui traite réellement des écritures
        // budgétaires. Sa contrepartie est le journal d'activité, qui trace
        // qui a saisi, modifie ou valide quoi.
        $administrateur = new Role('administrateur', 'Administrateur', true);
        $accorder($administrateur, $permissions, array_keys($permissions));
        $this->em->persist($administrateur);

        $lecteur = new Role('lecteur', 'Lecteur', true);
        $accorder($lecteur, $permissions, ['activites.consulter', 'indicateurs.consulter']);

        $roles = [
            'administrateur' => $administrateur,
            'coordonnateur' => $coordonnateur,
            'responsable_suivi_evaluation' => $suiviEvaluation,
            'gestionnaire_budgetaire' => $gestionnaireBudgetaire,
            'point_focal_structure' => $pointFocalStructure,
            'lecteur' => $lecteur,
        ];

        foreach ($roles as $role) {
            $this->em->persist($role);
        }

        return $roles;
    }

    /** Seuils de vigilance § 5.3 — éditables ensuite depuis /admin/parametres (P6). */
    public function semerParametres(): int
    {
        $definitions = [
            ['vigilance.seuil_retard', '-0.10', "Écart taux d'exécution / taux attendu en-deçà duquel une activité passe en \"en retard\""],
            ['vigilance.seuil_risque', '-0.20', "Écart taux d'exécution / taux attendu en-deçà duquel une activité passe en \"risque élevé\""],
            ['rapport.echeance_jours', '15', 'Délai (jours) après la fin du trimestre pour transmettre le rapport périodique'],
            ['initiatives.seuil_duree_risque', '0.6667', 'Fraction de la durée d\'une activité de projet écoulée à partir de laquelle une faible progression déclenche "à risque"'],
            ['initiatives.seuil_ecart_risque', '-0.20', 'Écart progression / durée écoulée en-deçà duquel une activité de projet passe "à risque"'],
            ['alertes.destinataires', '', 'Adresses destinataires des alertes planifiées, séparées par des virgules (vide : les comptes coordonnateur et suivi-évaluation)'],
            ['alertes.contrat_jours', '30,15,7,0', "Jours restants déclenchant une alerte d'échéance de contrat"],
            ['alertes.relance_intervalle_jours', '7', 'Délai minimal (jours) entre deux relances automatiques du même rapport'],
        ];

        foreach ($definitions as [$code, $valeur, $description]) {
            $this->em->persist(new Parametre($code, $valeur, $description));
        }

        return \count($definitions);
    }

    /**
     * Premier compte d'administration, créé seulement s'il n'existe pas.
     *
     * Le mot de passe est temporaire par construction : `doitChangerMdp` est
     * vrai sur tout compte créé, et l'application impose un changement à la
     * première connexion. C'est le seul moment où un mot de passe transite
     * en clair ; il est affiché à l'écran plutôt qu'écrit dans un fichier.
     */
    public function creerAdministrateur(string $email, string $motDePasseTemporaire, Role $role): ?Utilisateur
    {
        if (null !== $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email])) {
            return null;
        }

        $administrateur = new Utilisateur($email, 'temporaire', 'Administrateur', 'SISEP');
        $administrateur->setFonction('Administrateur de la plateforme');
        $administrateur->setMotDePasseHash($this->hacheur->hashPassword($administrateur, $motDePasseTemporaire));
        $this->em->persist($administrateur);
        $this->em->persist(new UtilisateurRole($administrateur, $role, null));

        return $administrateur;
    }

    /**
     * Nouveau mot de passe temporaire pour un compte existant — le recours
     * quand le mot de passe d'amorçage a été perdu. Le changement reste
     * imposé à la connexion suivante, et l'écriture passe par le journal
     * d'activité comme toute modification de compte.
     */
    public function reinitialiserMotDePasse(Utilisateur $utilisateur, string $motDePasseTemporaire): void
    {
        $utilisateur->setMotDePasseHash($this->hacheur->hashPassword($utilisateur, $motDePasseTemporaire));
        $utilisateur->exigerChangementMdp();
        $utilisateur->setActif(true);
    }
}
