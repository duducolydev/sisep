<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Activite;
use App\Entity\Composante;
use App\Entity\Dotation;
use App\Entity\Exercice;
use App\Entity\Projet;
use App\Entity\SousActivite;
use App\Entity\SousComposante;
use App\Entity\Structure;
use App\Entity\Utilisateur;
use App\Enum\TypeStructure;
use App\Ptba\LecteurPtba;
use App\Ptba\LignePtba;
use App\Repository\ActiviteRepository;
use App\Repository\ComposanteRepository;
use App\Repository\DotationRepository;
use App\Repository\ExerciceRepository;
use App\Repository\ProjetRepository;
use App\Repository\SousActiviteRepository;
use App\Repository\SousComposanteRepository;
use App\Repository\StructureRepository;
use App\Repository\UtilisateurRepository;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Charge le PTBA annuel du PHASAOC depuis son classeur : composantes,
 * sous-composantes, activités, sous-activités et dotations de l'exercice.
 *
 * C'est l'alimentation réelle du projet, par opposition à `app:db:seed` qui
 * pose un jeu de démonstration. Les deux ne se mélangent pas : sur une base
 * de production, on migre, on importe le PTBA, puis les points focaux et le
 * personnel. Le seed n'y a pas sa place.
 *
 * Relancer est sans risque : tout est rapproché par code hiérarchique, et
 * une ligne déjà connue est mise à jour — libellé, montants, répartition
 * trimestrielle — au lieu d'être dupliquée. Un PTBA révisé en cours d'année
 * se recharge donc par-dessus le précédent, et le journal d'activité garde
 * la trace de chaque montant modifié.
 *
 * Ce que l'import ne fait jamais : supprimer. Une ligne disparue du classeur
 * reste en base, parce qu'elle peut déjà porter des engagements et des
 * dépenses. Les lignes orphelines sont listées en fin d'exécution, à l'UGP
 * de trancher.
 */
#[AsCommand(
    name: 'app:ptba:importer',
    description: 'Importe le PTBA (composantes, activités et dotations) depuis son classeur .xlsx',
)]
final class ImporterPtbaCommand extends Command
{
    /**
     * Structure portant les activités tant que les points focaux ne sont pas
     * chargés : le PTBA ne dit pas qui pilote quoi, c'est le classeur des
     * contacts qui le dit. L'ANSD est chef de file du projet, donc le
     * responsable par défaut le moins faux.
     */
    private const SIGLE_PAR_DEFAUT = 'ANSD';

    /** @var list<string> écarts de montant notifié entre la base et le classeur */
    private array $ecarts = [];

    public function __construct(
        private readonly LecteurPtba $lecteur,
        private readonly ProjetRepository $projets,
        private readonly ExerciceRepository $exercices,
        private readonly ComposanteRepository $composantes,
        private readonly SousComposanteRepository $sousComposantes,
        private readonly ActiviteRepository $activites,
        private readonly SousActiviteRepository $sousActivites,
        private readonly DotationRepository $dotations,
        private readonly StructureRepository $structures,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(ADMIN_EMAIL)%')] private readonly string $emailAdministrateur = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Classeur .xlsx du PTBA')
            ->addOption('feuille', null, InputOption::VALUE_REQUIRED, 'Onglet à lire', LecteurPtba::FEUILLE_PAR_DEFAUT)
            ->addOption('annee', null, InputOption::VALUE_REQUIRED, 'Exercice budgétaire alimenté', (string) (int) date('Y'))
            ->addOption('auteur', null, InputOption::VALUE_REQUIRED, 'Adresse du compte au nom duquel les dotations sont saisies (défaut : ADMIN_EMAIL)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse le classeur et affiche le bilan sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');

        /** @var string $fichier */
        $fichier = $input->getArgument('fichier');
        $annee = (int) $input->getOption('annee');

        try {
            $lignes = $this->lecteur->lire($fichier, (string) $input->getOption('feuille'));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $auteur = $this->auteur($input->getOption('auteur'));
        if (null === $auteur) {
            $io->error('Aucun compte pour signer les dotations : préciser --auteur=adresse ou renseigner ADMIN_EMAIL.');

            return Command::FAILURE;
        }

        $projet = $this->projets->findOneBy([]) ?? $this->creerProjet($simulation);
        $exercice = $this->exercices->trouverParAnnee($projet, $annee) ?? $this->creerExercice($projet, $annee, $simulation);
        $structureDefaut = $this->structureParDefaut($simulation);

        $io->section(\sprintf('%d lignes lues dans %s (onglet %s)', \count($lignes), basename($fichier), (string) $input->getOption('feuille')));
        $io->text(\sprintf('Projet « %s », exercice %d, dotations au nom de %s', $projet->getCode(), $annee, $auteur->getEmail()));

        // Les lignes portant une dotation sont les feuilles de l'arbre : une
        // sous-activité, ou une activité qui n'en a aucune. Doter aussi les
        // niveaux supérieurs compterait deux fois le même argent, puisqu'ils
        // sont la somme de leurs enfants.
        $codesParents = [];
        foreach ($lignes as $ligne) {
            $parent = $ligne->codeParent();
            if (null !== $parent) {
                $codesParents[$parent] = true;
            }
        }

        $composantes = [];
        $sousComposantes = [];
        $activites = [];
        $compteurs = ['composante' => 0, 'sousComposante' => 0, 'activite' => 0, 'sousActivite' => 0, 'dotation' => 0, 'dotationMaj' => 0];
        $orphelines = [];

        foreach ($lignes as $ligne) {
            switch ($ligne->niveau) {
                case 1:
                    $composantes[$ligne->code] = $this->composante($projet, $ligne, $compteurs, $simulation);
                    break;

                case 2:
                    $parent = $composantes[(string) $ligne->codeParent()] ?? null;
                    if (null === $parent) {
                        $orphelines[] = $ligne;
                        break;
                    }
                    $sousComposantes[$ligne->code] = $this->sousComposante($parent, $ligne, $compteurs, $simulation);
                    break;

                case 3:
                    $parent = $sousComposantes[(string) $ligne->codeParent()] ?? null;
                    if (null === $parent) {
                        $orphelines[] = $ligne;
                        break;
                    }
                    $activite = $this->activite($parent, $ligne, $structureDefaut, $compteurs, $simulation);
                    $activites[$ligne->code] = $activite;
                    if (!isset($codesParents[$ligne->code])) {
                        $this->doter($activite, $exercice, $ligne, $auteur, $compteurs, $simulation);
                    }
                    break;

                case 4:
                    $parent = $activites[(string) $ligne->codeParent()] ?? null;
                    if (null === $parent) {
                        $orphelines[] = $ligne;
                        break;
                    }
                    $sousActivite = $this->sousActivite($parent, $ligne, $compteurs, $simulation);
                    $this->doter($sousActivite, $exercice, $ligne, $auteur, $compteurs, $simulation);
                    break;

                default:
                    $orphelines[] = $ligne;
            }
        }

        if (!$simulation) {
            $this->em->flush();
        }

        $io->definitionList(
            ['Composantes' => (string) $compteurs['composante']],
            ['Sous-composantes' => (string) $compteurs['sousComposante']],
            ['Activités' => (string) $compteurs['activite']],
            ['Sous-activités' => (string) $compteurs['sousActivite']],
            ['Dotations créées' => (string) $compteurs['dotation']],
            ['Dotations mises à jour' => (string) $compteurs['dotationMaj']],
        );

        if ([] !== $this->ecarts) {
            $io->warning(\sprintf(
                "%d dotation(s) déjà saisie(s) avec un montant différent du classeur. Le notifié n'a pas été réécrit — "
                ."saisir la révision depuis l'écran du budget si elle est validée :\n- %s",
                \count($this->ecarts),
                implode("\n- ", $this->ecarts),
            ));
        }

        if ([] !== $orphelines) {
            $io->warning(\sprintf(
                "%d ligne(s) sans parent dans le classeur, ignorées :\n- %s",
                \count($orphelines),
                implode("\n- ", array_map(static fn (LignePtba $l) => \sprintf('ligne %d — %s %s', $l->ligne, $l->code, $l->libelle), $orphelines)),
            ));
        }

        if ($simulation) {
            $io->note("Simulation (--dry-run) : aucune écriture n'a été faite.");

            return Command::SUCCESS;
        }

        $io->success('PTBA importé.');

        return Command::SUCCESS;
    }

    /** @param array<string, int> $compteurs */
    private function composante(Projet $projet, LignePtba $ligne, array &$compteurs, bool $simulation): Composante
    {
        $composante = $this->composantes->findOneBy(['projet' => $projet, 'code' => $ligne->code]);
        if (null === $composante) {
            $composante = new Composante($projet, $ligne->code, $ligne->libelle);
            $composante->setOrdre($compteurs['composante']);
            if (!$simulation) {
                $this->em->persist($composante);
            }
        } else {
            $composante->setLibelle($ligne->libelle);
        }
        ++$compteurs['composante'];

        return $composante;
    }

    /** @param array<string, int> $compteurs */
    private function sousComposante(Composante $parent, LignePtba $ligne, array &$compteurs, bool $simulation): SousComposante
    {
        $sousComposante = $this->sousComposantes->findOneBy(['code' => $ligne->code]);
        if (null === $sousComposante) {
            $sousComposante = new SousComposante($parent, $ligne->code, $ligne->libelle);
            $sousComposante->setOrdre($compteurs['sousComposante']);
            if (!$simulation) {
                $this->em->persist($sousComposante);
            }
        } else {
            $sousComposante->setLibelle($ligne->libelle);
        }
        ++$compteurs['sousComposante'];

        return $sousComposante;
    }

    /** @param array<string, int> $compteurs */
    private function activite(SousComposante $parent, LignePtba $ligne, Structure $defaut, array &$compteurs, bool $simulation): Activite
    {
        $activite = $this->activites->findOneBy(['code' => $ligne->code]);
        if (null === $activite) {
            // La structure responsable réelle vient du classeur des points
            // focaux : ici, seulement de quoi satisfaire la contrainte.
            $activite = new Activite($parent, $ligne->code, $ligne->libelle, $defaut);
            $activite->setOrdre($compteurs['activite']);
            if (!$simulation) {
                $this->em->persist($activite);
            }
        } else {
            $activite->setLibelle($ligne->libelle);
        }
        ++$compteurs['activite'];

        return $activite;
    }

    /** @param array<string, int> $compteurs */
    private function sousActivite(Activite $parent, LignePtba $ligne, array &$compteurs, bool $simulation): SousActivite
    {
        $existante = null;
        foreach ($this->sousActivites->findBy(['activite' => $parent]) as $candidate) {
            if ($candidate->getLibelle() === $ligne->libelle) {
                $existante = $candidate;
                break;
            }
        }

        if (null === $existante) {
            // La sous-activité n'a pas de code en base : le rapprochement se
            // fait sur le libellé, seul identifiant stable que le classeur
            // lui donne. Un libellé retouché crée donc une ligne de plus,
            // signalée comme telle par le décompte.
            $existante = new SousActivite($parent, $ligne->libelle);
            $existante->setOrdre($compteurs['sousActivite']);
            if (!$simulation) {
                $this->em->persist($existante);
            }
        }
        ++$compteurs['sousActivite'];

        return $existante;
    }

    /** @param array<string, int> $compteurs */
    private function doter(
        Activite|SousActivite $cible,
        Exercice $exercice,
        LignePtba $ligne,
        Utilisateur $auteur,
        array &$compteurs,
        bool $simulation,
    ): void {
        // Une ligne planifiée mais non budgétée cette année ne reçoit pas de
        // dotation à zéro : elle encombrerait l'arbre d'exécution sans rien
        // y apprendre.
        if (0 === $ligne->montant) {
            return;
        }

        $dotation = $this->dotations->trouverPourCibleExercice($cible, $exercice);
        if (null === $dotation) {
            $dotation = new Dotation($cible, $exercice, Montant::fromFcfa($ligne->montant), $auteur);
            if (!$simulation) {
                $this->em->persist($dotation);
            }
            ++$compteurs['dotation'];
        } else {
            // Le montant notifié d'une dotation ne se réécrit pas : il est
            // fixé à la saisie, et une révision s'exprime par le montant
            // validé en COPIL, pas en effaçant ce qui a été notifié (c'est
            // aussi pourquoi l'API n'expose aucun PATCH sur les dotations).
            // Un écart est donc signalé, jamais appliqué en silence.
            if ($dotation->getMontant()->versFcfa() !== $ligne->montant) {
                $this->ecarts[] = \sprintf(
                    '%s — notifié en base %s FCFA, classeur %s FCFA',
                    $ligne->code,
                    number_format($dotation->getMontant()->versFcfa(), 0, ',', ' '),
                    number_format($ligne->montant, 0, ',', ' '),
                );
            }
            ++$compteurs['dotationMaj'];
        }

        $dotation->setQ1($this->montantOuNull($ligne->trimestre1));
        $dotation->setQ2($this->montantOuNull($ligne->trimestre2));
        $dotation->setQ3($this->montantOuNull($ligne->trimestre3));
        $dotation->setQ4($this->montantOuNull($ligne->trimestre4));
        $dotation->setMontantValideCopil($this->montantOuNull($ligne->montantValideCopil));
        $dotation->setCommentaireRevision($ligne->commentaire);
    }

    private function montantOuNull(?int $valeur): ?Montant
    {
        return null === $valeur ? null : Montant::fromFcfa($valeur);
    }

    private function auteur(mixed $option): ?Utilisateur
    {
        $email = \is_string($option) && '' !== trim($option) ? trim($option) : $this->emailAdministrateur;

        return '' === $email ? null : $this->utilisateurs->findOneBy(['email' => $email]);
    }

    private function creerProjet(bool $simulation): Projet
    {
        $projet = new Projet(
            'PHASAOC',
            "Projet d'Harmonisation et d'Amélioration des Statistiques en Afrique de l'Ouest et du Centre",
            'Banque mondiale',
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2028-12-31'),
        );
        if (!$simulation) {
            $this->em->persist($projet);
        }

        return $projet;
    }

    private function creerExercice(Projet $projet, int $annee, bool $simulation): Exercice
    {
        $exercice = new Exercice(
            $projet,
            $annee,
            new \DateTimeImmutable(\sprintf('%d-01-01', $annee)),
            new \DateTimeImmutable(\sprintf('%d-12-31', $annee)),
        );
        if (!$simulation) {
            $this->em->persist($exercice);
        }

        return $exercice;
    }

    private function structureParDefaut(bool $simulation): Structure
    {
        $structure = $this->structures->findOneBy(['sigle' => self::SIGLE_PAR_DEFAUT]);
        if (null === $structure) {
            $structure = new Structure(
                self::SIGLE_PAR_DEFAUT,
                'Agence Nationale de la Statistique et de la Démographie',
                TypeStructure::CHEF_DE_FILE,
            );
            if (!$simulation) {
                $this->em->persist($structure);
            }
        }

        return $structure;
    }
}
