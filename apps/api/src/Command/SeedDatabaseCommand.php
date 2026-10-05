<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\Exercice;
use App\Entity\Indicateur;
use App\Entity\IndicateurValeur;
use App\Entity\Parametre;
use App\Entity\Permission;
use App\Entity\PieceJointe;
use App\Entity\PointFocal;
use App\Entity\Projet;
use App\Entity\RapportPeriodique;
use App\Entity\Realisation;
use App\Entity\Role;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurRole;
use App\Enum\FrequenceIndicateur;
use App\Enum\NatureEngagement;
use App\Enum\PeriodeTrimestre;
use App\Enum\SensIndicateur;
use App\Enum\StatutActivite;
use App\Enum\TypeIndicateur;
use App\Enum\TypeStructure;
use App\ValueObject\Montant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données de démonstration aligné sur le PTBA 2026 réel du PHASAOC
 * (cf. docblock de `DataFixtures/donnees_demonstration.php` pour la
 * provenance exacte) : 4 composantes, 11 sous-composantes, 26 activités,
 * 12 indicateurs, 12 structures — structure et montants budgétaires
 * (dotations, trimestres, révisions COPIL) fidèles au document source ;
 * l'exécution (engagé/dépensé) et le statut d'avancement physique des
 * sous-activités sont simulés, faute de données réelles disponibles.
 */
#[AsCommand(name: 'app:db:seed', description: 'Peuple la base avec le jeu de données de démonstration issu du PTBA 2026')]
final class SeedDatabaseCommand extends Command
{
    /** @var array<string, TypeStructure> */
    private const TYPES_STRUCTURE = [
        'Chef de file' => TypeStructure::CHEF_DE_FILE,
        'Direction ANSD' => TypeStructure::DIRECTION_ANSD,
        'Cellule ANSD' => TypeStructure::CELLULE_ANSD,
        'Partenaire — formation' => TypeStructure::PARTENAIRE_FORMATION,
        'Partenaire national' => TypeStructure::PARTENAIRE_NATIONAL,
        'Partenaire technique' => TypeStructure::PARTENAIRE_TECHNIQUE,
        'Bailleur' => TypeStructure::BAILLEUR,
    ];

    /** La "Coordination" de la maquette n'est pas une structure séparée : elle relève de l'ANSD (chef de file, § 1). */
    private const SIGLE_COORDINATION = 'ANSD';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly UserPasswordHasherInterface $passwordHasher,
        // Compte d'administration propre à l'installation, hors jeu de
        // démonstration : il se déclare dans .env.local (non versionné) pour
        // qu'aucune adresse réelle n'entre dans le dépôt, et il est recréé à
        // chaque reseed.
        #[Autowire('%env(ADMIN_EMAIL)%')] private readonly string $emailAdministrateur = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Vide les tables applicatives avant de re-semer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projetExistant = $this->em->getRepository(Projet::class)->findOneBy(['code' => 'PHASAOC']);
        if (null !== $projetExistant) {
            if (!$input->getOption('force')) {
                $io->warning('Le projet PHASAOC existe déjà. Relancez avec --force pour vider et re-semer.');

                return Command::SUCCESS;
            }
            $this->viderLesTables();
        }

        /** @var array{acts: array<int, array<string, mixed>>, ind: array<int, array<string, mixed>>, str: array<int, array<string, mixed>>} $donnees */
        $donnees = require __DIR__.'/../DataFixtures/donnees_demonstration.php';

        $io->section('Référentiel des droits');
        $permissions = $this->semerPermissions();
        $roles = $this->semerRoles($permissions);
        $io->text(\sprintf('%d permissions, %d rôles', \count($permissions), \count($roles)));

        $io->section('Structures et points focaux');
        $structures = $this->semerStructures($donnees['str']);
        $pointsFocaux = $this->semerPointsFocaux($structures, $donnees['str']);
        $io->text(\sprintf('%d structures', \count($structures)));

        $io->section('Comptes de démonstration');
        $utilisateurs = $this->semerUtilisateurs($structures, $roles);
        // Les deux points focaux de démonstration (§ 10, critère P1) sont
        // rattachés à leur fiche point focal, pas seulement à leur structure.
        $pointsFocaux['DSID']->setUtilisateur($utilisateurs['focal_dsid']);
        $pointsFocaux['DPEE']->setUtilisateur($utilisateurs['focal_dpee']);
        $io->text(\sprintf('%d comptes', \count($utilisateurs)));

        $io->section('Projet et exercice');
        [$projet, $exercice, $exercicesSupplementaires] = $this->semerProjetEtExercice();

        $io->section('Structure du projet, budget et suivi');
        [$activites, $composantesCount, $sousComposantesCount, $sousActivitesCount] = $this->semerStructureProjet(
            $donnees['acts'],
            $projet,
            $exercice,
            $structures,
            $utilisateurs,
        );
        $io->text(\sprintf(
            '%d composantes, %d sous-composantes, %d activités, %d sous-activités',
            $composantesCount,
            $sousComposantesCount,
            \count($activites),
            $sousActivitesCount,
        ));

        $io->section('Budget pluriannuel');
        $nbAnneesBudget = $this->semerBudgetPluriannuel($activites, $exercicesSupplementaires, $utilisateurs);
        $io->text(\sprintf('%d exercices supplémentaires budgétés (2025, 2027, 2028)', $nbAnneesBudget));

        $io->section('Indicateurs');
        $nbIndicateurs = $this->semerIndicateurs($donnees['ind'], $projet, $activites, $utilisateurs, $structures);
        $io->text(\sprintf('%d indicateurs', $nbIndicateurs));

        $io->section('Reporting trimestriel');
        $nbRapports = $this->semerRapports($donnees['str'], $structures, $exercice, $utilisateurs);
        $io->text(\sprintf('%d rapports périodiques', $nbRapports));

        $io->section('Paramètres');
        $nbParametres = $this->semerParametres();
        $io->text(\sprintf('%d paramètres', $nbParametres));

        $this->em->flush();

        $io->success('Base peuplée avec le jeu de données de démonstration.');

        return Command::SUCCESS;
    }

    private function viderLesTables(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'journal_activite', 'contrats', 'personnels', 'effectifs_enqueteurs',
            'invitations_indicateurs', 'indicateur_desagregations', 'indicateur_valeurs', 'indicateurs',
            'realisations', 'depenses', 'engagements', 'dotations', 'sous_activites', 'activites',
            'sous_composantes', 'composantes', 'exercices', 'projets',
            'rapports_periodiques', 'pieces_jointes', 'points_focaux',
            'utilisateur_roles', 'role_permissions', 'roles', 'permissions',
            'utilisateurs', 'structures', 'parametres',
        ] as $table) {
            $this->connection->executeStatement("TRUNCATE TABLE {$table}");
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        // TRUNCATE réinitialise les AUTO_INCREMENT en base sans que
        // l'EntityManager ne le sache : sans ce clear(), la carte d'identité
        // garde l'ancien Projet(id: 1) et entre en collision avec le nouveau
        // qui reçoit le même id.
        $this->em->clear();
    }

    /** @return array<string, Permission> code => Permission */
    private function semerPermissions(): array
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
    private function semerRoles(array $permissions): array
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
            'rh.consulter', 'rh.gerer',
        ]);

        $suiviEvaluation = new Role('responsable_suivi_evaluation', 'Responsable suivi-évaluation', true);
        $accorder($suiviEvaluation, $permissions, [
            'activites.consulter', 'activites.creer', 'activites.modifier', 'activites.valider',
            'indicateurs.consulter', 'indicateurs.definir', 'indicateurs.saisir', 'indicateurs.inviter',
            'journal.consulter', 'exports.generer', 'initiatives.gerer', 'admin.parametres',
            'rh.consulter', 'rh.gerer',
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

    /**
     * @param array<int, array<string, mixed>> $structuresData
     *
     * @return array<string, Structure> sigle => Structure
     */
    private function semerStructures(array $structuresData): array
    {
        $structures = [];
        foreach ($structuresData as $s) {
            $type = self::TYPES_STRUCTURE[$s['type']] ?? throw new \RuntimeException("Type de structure inconnu : {$s['type']}");
            $structure = new Structure($s['sigle'], $s['nom'], $type);
            $structure->setTelephone('—' !== $s['tel'] ? $s['tel'] : null);
            // L'email est désormais obligatoire à la création (système
            // d'alertes par courriel) : le jeu de démonstration en fournit un
            // pour chaque structure, jamais null.
            $structure->setEmail($s['email']);
            $this->em->persist($structure);
            $structures[$s['sigle']] = $structure;
        }

        return $structures;
    }

    /**
     * @param array<string, Structure>         $structures
     * @param array<int, array<string, mixed>> $structuresData
     *
     * @return array<string, PointFocal> sigle => PointFocal
     */
    private function semerPointsFocaux(array $structures, array $structuresData): array
    {
        $pointsFocaux = [];
        foreach ($structuresData as $s) {
            $pointFocal = new PointFocal($structures[$s['sigle']], 'Point focal — '.$s['sigle'], $s['focal']);
            $pointFocal->setTelephone('—' !== $s['tel'] ? $s['tel'] : null);
            $this->em->persist($pointFocal);
            $pointsFocaux[$s['sigle']] = $pointFocal;
        }

        return $pointsFocaux;
    }

    /**
     * @param array<string, Structure> $structures
     * @param array<string, Role>      $roles
     *
     * @return array<string, Utilisateur>
     */
    private function semerUtilisateurs(array $structures, array $roles): array
    {
        $motDePasseHash = $this->passwordHasher->hashPassword(
            new Utilisateur('gabarit@sisep.local', '', 'Gabarit', 'Gabarit'),
            'ChangezMoi123!',
        );

        $creer = function (string $email, string $nom, string $prenom, string $fonction, ?Structure $structure) use ($motDePasseHash): Utilisateur {
            $utilisateur = new Utilisateur($email, $motDePasseHash, $nom, $prenom);
            $utilisateur->setFonction($fonction);
            $utilisateur->setStructure($structure);
            $this->em->persist($utilisateur);

            return $utilisateur;
        };

        $coordonnateur = $creer('coordination@ansd.sn', 'Diagne', 'Aïssatou', 'Coordonnatrice PHASAOC', $structures[self::SIGLE_COORDINATION]);
        $suiviEvaluation = $creer('suivi-evaluation@ansd.sn', 'Ndiaye', 'Moussa', 'Responsable suivi-évaluation', $structures[self::SIGLE_COORDINATION]);
        $gestionnaireBudgetaire = $creer('budget@ansd.sn', 'Fall', 'Khadija', 'Gestionnaire budgétaire', $structures['DAF']);
        $focalDsid = $creer('focal.dsid@ansd.sn', 'Sarr', 'Ibrahima', 'Point focal DSID', $structures['DSID']);
        $focalDpee = $creer('focal.dpee@dpee.sn', 'Ba', 'Fatou', 'Point focal DPEE', $structures['DPEE']);
        $lecteur = $creer('lecture@ansd.sn', 'Diop', 'Cheikh', 'Lecteur', null);

        $attribuer = function (Utilisateur $utilisateur, Role $role, ?Structure $structure = null): void {
            $this->em->persist(new UtilisateurRole($utilisateur, $role, $structure));
        };

        $attribuer($coordonnateur, $roles['coordonnateur']);
        $attribuer($suiviEvaluation, $roles['responsable_suivi_evaluation']);
        $attribuer($gestionnaireBudgetaire, $roles['gestionnaire_budgetaire']);
        $attribuer($focalDsid, $roles['point_focal_structure'], $structures['DSID']);
        $attribuer($focalDpee, $roles['point_focal_structure'], $structures['DPEE']);
        $attribuer($lecteur, $roles['lecteur']);

        if ('' !== $this->emailAdministrateur) {
            $administrateur = $creer($this->emailAdministrateur, 'Administrateur', 'SISEP', 'Administrateur de la plateforme', null);
            $attribuer($administrateur, $roles['administrateur']);
        }

        return [
            'coordonnateur' => $coordonnateur,
            'suivi_evaluation' => $suiviEvaluation,
            'gestionnaire_budgetaire' => $gestionnaireBudgetaire,
            'focal_dsid' => $focalDsid,
            'focal_dpee' => $focalDpee,
            'lecteur' => $lecteur,
        ];
    }

    /** @return array{0: Projet, 1: Exercice} */
    /**
     * @return array{0: Projet, 1: Exercice, 2: array<int, Exercice>} projet, exercice courant (2026, seul
     *                                                                exercice détaillé ligne à ligne par le PTBA), exercices supplémentaires (année => Exercice)
     *                                                                pour le graphique d'exécution pluriannuelle (§ Budget) — cf. semerBudgetPluriannuel().
     */
    private function semerProjetEtExercice(): array
    {
        $projet = new Projet(
            'PHASAOC',
            "Projet d'Harmonisation et d'Amélioration des Statistiques en Afrique de l'Ouest et du Centre",
            'Banque mondiale',
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2028-12-31'),
        );
        $this->em->persist($projet);

        $exercice = new Exercice($projet, 2026, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($exercice);

        $exercicesSupplementaires = [];
        foreach ([2025, 2027, 2028] as $annee) {
            $exerciceAnnee = new Exercice($projet, $annee, new \DateTimeImmutable("{$annee}-01-01"), new \DateTimeImmutable("{$annee}-12-31"));
            $this->em->persist($exerciceAnnee);
            $exercicesSupplementaires[$annee] = $exerciceAnnee;
        }

        return [$projet, $exercice, $exercicesSupplementaires];
    }

    private function mapperStatut(string $st): StatutActivite
    {
        return match ($st) {
            'fait' => StatutActivite::TERMINEE,
            'cours', 'retard', 'risque' => StatutActivite::EN_COURS,
            'prevu' => StatutActivite::PLANIFIEE,
            default => throw new \RuntimeException("Statut inconnu : {$st}"),
        };
    }

    /** @param array<string, Structure> $structures */
    private function resoudreStructure(array $structures, string $sigle): Structure
    {
        return $structures[$sigle] ?? $structures[self::SIGLE_COORDINATION];
    }

    /**
     * @param array<int, array<string, mixed>> $activitesData
     * @param array<string, Structure>         $structures
     * @param array<string, Utilisateur>       $utilisateurs
     *
     * @return array{0: array<string, Activite>, 1: int, 2: int, 3: int}
     */
    private function semerStructureProjet(
        array $activitesData,
        Projet $projet,
        Exercice $exercice,
        array $structures,
        array $utilisateurs,
    ): array {
        $composantes = [];
        $sousComposantes = [];
        $ordreComposante = 0;
        $ordresSousComposante = [];
        $activites = [];
        $sousActivitesCount = 0;

        $gestionnaire = $utilisateurs['gestionnaire_budgetaire'];
        $coordonnateur = $utilisateurs['coordonnateur'];
        $suiviEvaluation = $utilisateurs['suivi_evaluation'];

        foreach ($activitesData as $a) {
            if (!isset($composantes[$a['comp']])) {
                $composante = new Composante($projet, $a['comp'], $a['compt']);
                $composante->setOrdre($ordreComposante++);
                $this->em->persist($composante);
                $composantes[$a['comp']] = $composante;
                $ordresSousComposante[$a['comp']] = 0;
            }

            $cleSousComposante = $a['comp'].'|'.$a['sc'];
            if (!isset($sousComposantes[$cleSousComposante])) {
                $sousComposante = new SousComposante($composantes[$a['comp']], $a['sc'], $a['sct']);
                $sousComposante->setOrdre($ordresSousComposante[$a['comp']]++);
                $this->em->persist($sousComposante);
                $sousComposantes[$cleSousComposante] = $sousComposante;
            }

            $structureResponsable = $this->resoudreStructure($structures, $a['resp']);
            $activite = new Activite($sousComposantes[$cleSousComposante], $a['code'], $a['titre'], $structureResponsable);
            $activite->setStatut($this->mapperStatut($a['st']));

            $echeances = [];
            foreach ($a['sa'] as $index => $sa) {
                $echeance = \DateTimeImmutable::createFromFormat('d/m/Y', $sa['ech']) ?: null;
                if (null !== $echeance) {
                    $echeances[] = ['date' => $echeance, 'fait' => 'fait' === $sa['st']];
                }

                $sousActivite = new SousActivite($activite, $sa['t']);
                $sousActivite->setStatut($this->mapperStatut($sa['st']));
                $sousActivite->setStructureResponsable($this->resoudreStructure($structures, $sa['resp']));
                $sousActivite->setEcheance($echeance);
                $sousActivite->setOrdre($index);
                $this->em->persist($sousActivite);

                // Déclenche RealisationListener::onFlush, qui met à jour
                // sous_activite.avancement — c'est le "trigger applicatif" du § 4.6.
                $realisation = new Realisation($sousActivite, $sa['av'], new \DateTimeImmutable('today'), $suiviEvaluation);
                $this->em->persist($realisation);

                // Ligne chiffrée du PTBA au niveau sous-activité (ex. RGPH-5, EHCVM) — § 6.2.
                $this->creerBudgetSiPresent($sousActivite, $exercice, $sa['budget'] ?? null, $gestionnaire, $coordonnateur);

                ++$sousActivitesCount;
            }

            if ([] !== $echeances) {
                $dateFinPrevue = array_reduce($echeances, static fn (?\DateTimeImmutable $max, array $e) => null === $max || $e['date'] > $max ? $e['date'] : $max, null);
                $activite->setDateFinPrevue($dateFinPrevue);

                if (!\in_array(false, array_column($echeances, 'fait'), true)) {
                    $activite->setDateFinReelle($dateFinPrevue);
                }
            }

            $this->em->persist($activite);
            $activites[$a['code']] = $activite;

            // Ligne chiffrée du PTBA directement au niveau activité (pas de
            // sous-activité en-dessous, ex. 4.1.1 Gestion programme) — § 6.2.
            $this->creerBudgetSiPresent($activite, $exercice, $a['budget'] ?? null, $gestionnaire, $coordonnateur);
        }

        return [$activites, \count($composantes), \count($sousComposantes), $sousActivitesCount];
    }

    /**
     * Chaînage dotation → engagement → dépense (§ 4.3, § 5.1) à partir d'une
     * ligne budgétaire du PTBA ('montant'/'q1'-'q4'/'copil'/'commentaire' —
     * FCFA exacts, pas d'échelle) : aucune dotation n'est créée pour les
     * lignes à 0 FCFA (planifiées mais non budgétées cette année).
     *
     * @param array{montant: int, q1: ?int, q2: ?int, q3: ?int, q4: ?int, copil: ?int, commentaire: ?string, engage: int, depense: int}|null $budget
     */
    private function creerBudgetSiPresent(
        Activite|SousActivite $cible,
        Exercice $exercice,
        ?array $budget,
        Utilisateur $gestionnaire,
        Utilisateur $coordonnateur,
    ): void {
        if (null === $budget || 0 === $budget['montant']) {
            return;
        }

        $dotation = new Dotation($cible, $exercice, Montant::fromFcfa($budget['montant']), $gestionnaire);
        $dotation->setQ1(null !== $budget['q1'] ? Montant::fromFcfa($budget['q1']) : null);
        $dotation->setQ2(null !== $budget['q2'] ? Montant::fromFcfa($budget['q2']) : null);
        $dotation->setQ3(null !== $budget['q3'] ? Montant::fromFcfa($budget['q3']) : null);
        $dotation->setQ4(null !== $budget['q4'] ? Montant::fromFcfa($budget['q4']) : null);
        $dotation->setMontantValideCopil(null !== $budget['copil'] ? Montant::fromFcfa($budget['copil']) : null);
        $dotation->setCommentaireRevision($budget['commentaire']);
        $this->em->persist($dotation);

        if ($budget['engage'] > 0) {
            $engagement = new Engagement(
                $cible,
                $exercice,
                NatureEngagement::PRESTATION_INTELLECTUELLE,
                Montant::fromFcfa($budget['engage']),
                new \DateTimeImmutable('2026-03-01'),
                $gestionnaire,
            );
            $engagement->valider($coordonnateur);
            $this->em->persist($engagement);

            if ($budget['depense'] > 0) {
                $depense = new Depense($engagement, Montant::fromFcfa($budget['depense']), new \DateTimeImmutable('2026-06-01'), $gestionnaire);
                $depense->valider($coordonnateur);
                $this->em->persist($depense);
            }
        }
    }

    /**
     * Enrichit le budget avec quelques exercices supplémentaires (2025
     * clos, 2027/2028 à venir) — pas la réplication ligne à ligne du PTBA
     * comme creerBudgetSiPresent() fait pour 2026, seulement quelques
     * dotations représentatives sur des activités déjà seedées, pour que le
     * graphique d'exécution pluriannuelle (§ Budget) ait plusieurs années à
     * comparer. 2025 (exercice passé) est intégralement exécuté (dotation
     * → engagement validé → dépense validée, ~85 %) ; 2027/2028 (à venir)
     * n'ont qu'une dotation, rien engagé ni dépensé — cohérent avec le
     * tableau de bord de référence où les années futures n'affichent que
     * l'objectif.
     *
     * @param array<string, Activite>    $activites
     * @param array<int, Exercice>       $exercicesSupplementaires année => Exercice
     * @param array<string, Utilisateur> $utilisateurs
     */
    private function semerBudgetPluriannuel(array $activites, array $exercicesSupplementaires, array $utilisateurs): int
    {
        $gestionnaire = $utilisateurs['gestionnaire_budgetaire'];
        $coordonnateur = $utilisateurs['coordonnateur'];

        $codesChoisis = ['1.2.1', '1.2.2', '1.2.3', '1.3.1', '3.1.2'];
        $activitesChoisies = array_values(array_filter(
            array_map(static fn (string $code) => $activites[$code] ?? null, $codesChoisis),
        ));
        if ([] === $activitesChoisies) {
            return 0;
        }

        $anneesBudgetees = 0;

        if (isset($exercicesSupplementaires[2025])) {
            $exercice2025 = $exercicesSupplementaires[2025];
            foreach ($activitesChoisies as $i => $activite) {
                $montant = 15_000_000 + $i * 3_000_000;

                $dotation = new Dotation($activite, $exercice2025, Montant::fromFcfa($montant), $gestionnaire);
                $this->em->persist($dotation);

                $engagement = new Engagement(
                    $activite,
                    $exercice2025,
                    NatureEngagement::PRESTATION_INTELLECTUELLE,
                    Montant::fromFcfa($montant),
                    new \DateTimeImmutable('2025-03-01'),
                    $gestionnaire,
                );
                $engagement->valider($coordonnateur);
                $this->em->persist($engagement);

                $depense = new Depense($engagement, Montant::fromFcfa((int) round($montant * 0.85)), new \DateTimeImmutable('2025-11-01'), $gestionnaire);
                $depense->valider($coordonnateur);
                $this->em->persist($depense);
            }
            ++$anneesBudgetees;
        }

        foreach ([2027, 2028] as $annee) {
            if (!isset($exercicesSupplementaires[$annee])) {
                continue;
            }
            $exerciceAnnee = $exercicesSupplementaires[$annee];
            foreach ($activitesChoisies as $i => $activite) {
                $dotation = new Dotation($activite, $exerciceAnnee, Montant::fromFcfa(12_000_000 + $i * 2_500_000), $gestionnaire);
                $this->em->persist($dotation);
            }
            ++$anneesBudgetees;
        }

        return $anneesBudgetees;
    }

    /**
     * @param array<int, array<string, mixed>> $indicateursData
     * @param array<string, Activite>          $activites
     * @param array<string, Utilisateur>       $utilisateurs
     * @param array<string, Structure>         $structures
     */
    private function semerIndicateurs(array $indicateursData, Projet $projet, array $activites, array $utilisateurs, array $structures): int
    {
        $saisiPar = $utilisateurs['suivi_evaluation'];
        $validePar = $utilisateurs['coordonnateur'];

        // La composante d'un indicateur n'est pas nécessaire pour retrouver
        // la composante elle-même : on la relie via le code "comp" de la
        // maquette, résolu depuis les activités déjà créées.
        $composantesParCode = [];
        foreach ($activites as $activite) {
            $composante = $activite->getSousComposante()->getComposante();
            $composantesParCode[$composante->getCode()] = $composante;
        }

        foreach ($indicateursData as $ind) {
            $type = 'Objectif de développement' === $ind['type'] ? TypeIndicateur::IOD : TypeIndicateur::IRI;
            $frequence = FrequenceIndicateur::from(mb_strtolower($ind['freq']));
            $ancrage = $this->periodeCourante($frequence);

            $indicateur = new Indicateur(
                $projet,
                $ind['code'],
                $ind['nom'],
                $type,
                $ind['unite'],
                SensIndicateur::from($ind['sens']),
                (string) $ind['ref'],
                new \DateTimeImmutable('2025-01-01'),
                (string) $ind['cible'],
                new \DateTimeImmutable('2028-12-31'),
                $frequence,
                $ind['source'],
            );
            $indicateur->setPrecisionLibelle($ind['precision']);
            $indicateur->setComposante($composantesParCode[$ind['comp']] ?? null);
            // Structure responsable (§ 4.4) : seuls les indicateurs dont la
            // source nomme explicitement une structure en portent une — pas
            // de responsable inventé pour les autres (§ 10, exactitude des
            // données de démonstration).
            if (isset($ind['resp'])) {
                $indicateur->setStructureResponsable($structures[$ind['resp']] ?? null);
            }
            $this->em->persist($indicateur);

            $periodes = $this->calculerPeriodes($frequence, $ancrage, \count($ind['hist']));
            foreach ($ind['hist'] as $i => $valeur) {
                $indicateurValeur = new IndicateurValeur($indicateur, $periodes[$i], (string) $valeur, $saisiPar);
                $indicateurValeur->valider($validePar);
                $this->em->persist($indicateurValeur);
            }
        }

        return \count($indicateursData);
    }

    private function periodeCourante(FrequenceIndicateur $frequence): \DateTimeImmutable
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $mois = (int) $aujourdhui->format('n');

        return match ($frequence) {
            FrequenceIndicateur::MENSUELLE => $aujourdhui->modify('first day of this month'),
            FrequenceIndicateur::TRIMESTRIELLE => $aujourdhui->setDate((int) $aujourdhui->format('Y'), (\intdiv($mois - 1, 3) * 3) + 1, 1),
            FrequenceIndicateur::SEMESTRIELLE => $aujourdhui->setDate((int) $aujourdhui->format('Y'), $mois <= 6 ? 1 : 7, 1),
            FrequenceIndicateur::ANNUELLE => $aujourdhui->setDate((int) $aujourdhui->format('Y'), 1, 1),
        };
    }

    /** @return array<int, \DateTimeImmutable> du plus ancien au plus récent */
    private function calculerPeriodes(FrequenceIndicateur $frequence, \DateTimeImmutable $ancrage, int $nombre): array
    {
        $pas = match ($frequence) {
            FrequenceIndicateur::MENSUELLE => 1,
            FrequenceIndicateur::TRIMESTRIELLE => 3,
            FrequenceIndicateur::SEMESTRIELLE => 6,
            FrequenceIndicateur::ANNUELLE => 12,
        };

        $periodes = [];
        for ($i = $nombre - 1; $i >= 0; --$i) {
            $periodes[] = $ancrage->modify(\sprintf('-%d months', $i * $pas));
        }

        return $periodes;
    }

    /**
     * @param array<int, array<string, mixed>> $structuresData
     * @param array<string, Structure>         $structures
     * @param array<string, Utilisateur>       $utilisateurs
     */
    private function semerRapports(array $structuresData, array $structures, Exercice $exercice, array $utilisateurs): int
    {
        $auteur = $utilisateurs['suivi_evaluation'];
        $periode = PeriodeTrimestre::T3;
        $dateEcheance = new \DateTimeImmutable('2026-10-15');

        foreach ($structuresData as $s) {
            $rapport = new RapportPeriodique($structures[$s['sigle']], $exercice, $periode, $dateEcheance);
            $this->em->persist($rapport);

            if ('recu' === $s['rapport']) {
                // pieces_jointes.entite_id référence l'id interne du rapport,
                // qui n'existe qu'après flush (association polymorphe, § 4.6 —
                // pas de FK en base entre les deux tables).
                $this->em->flush();

                $pieceJointe = new PieceJointe(
                    'rapport_periodique',
                    $rapport->getId(),
                    \sprintf('rapport-%s-T3-2026.pdf', $s['sigle']),
                    \sprintf('var/demo/rapports/%s-T3-2026.pdf', $s['sigle']),
                    'application/pdf',
                    182_000,
                    $auteur,
                );
                $this->em->persist($pieceJointe);

                $rapport->transmettre($pieceJointe);
                $rapport->valider();
            }
        }

        return \count($structuresData);
    }

    /** Seuils de vigilance § 5.3 — éditables ensuite depuis /admin/parametres (P6). */
    private function semerParametres(): int
    {
        $definitions = [
            ['vigilance.seuil_retard', '-0.10', "Écart taux d'exécution / taux attendu en-deçà duquel une activité passe en \"en retard\""],
            ['vigilance.seuil_risque', '-0.20', "Écart taux d'exécution / taux attendu en-deçà duquel une activité passe en \"risque élevé\""],
            ['rapport.echeance_jours', '15', 'Délai (jours) après la fin du trimestre pour transmettre le rapport périodique'],
            ['initiatives.seuil_duree_risque', '0.6667', 'Fraction de la durée d\'une activité de projet écoulée à partir de laquelle une faible progression déclenche "à risque"'],
            ['initiatives.seuil_ecart_risque', '-0.20', 'Écart progression / durée écoulée en-deçà duquel une activité de projet passe "à risque"'],
        ];

        foreach ($definitions as [$code, $valeur, $description]) {
            $this->em->persist(new Parametre($code, $valeur, $description));
        }

        return \count($definitions);
    }
}
