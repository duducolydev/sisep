<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Activite;
use App\Entity\Contrat;
use App\Entity\Personnel;
use App\Repository\ActiviteRepository;
use App\Repository\PersonnelRepository;
use App\Rh\LecteurClasseurPersonnel;
use App\Rh\LignePersonnel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Charge l'état du personnel technique de l'UGP dans la base (§ 10 du CR du
 * 22/09/2026).
 *
 * Le classeur est un état mensuel : la commande est faite pour être relancée
 * à chaque mise à jour, donc elle rapproche par identité (prénom + nom,
 * faute de matricule) et met à jour au lieu de dupliquer. Un agent absent du
 * classeur n'est jamais supprimé : un départ se constate depuis l'écran RH,
 * pas par absence d'une ligne dans un état de paie.
 *
 * Les dates de contrat sont rarement renseignées dans le classeur. Faute de
 * mieux, l'UGP a arrêté la convention de l'exercice complet (demande du
 * 05/10/2026), d'où les valeurs par défaut de --date-debut/--date-fin. Toute
 * date présente dans le classeur l'emporte sur elles.
 *
 * Aucune donnée salariale n'est lue ni stockée (§ 14).
 */
#[AsCommand(
    name: 'app:rh:importer-personnel',
    description: "Importe l'état du personnel technique de l'UGP depuis un classeur .xlsx",
)]
final class ImporterPersonnelCommand extends Command
{
    /**
     * Corps repérés en préfixe de la fonction : « ISE/ Chargé d'étude
     * junior » porte deux informations, le corps et le poste, que le
     * tableau de bord croise séparément. La liste est fermée exprès :
     * « Archiviste/ DSID » ou « Webmaster / DSID » ont la même forme sans
     * que la partie gauche soit un corps, et découper à l'aveugle
     * fabriquerait des profils qui n'existent pas.
     */
    private const CORPS = ['ISE', 'ITS', 'AS'];

    public function __construct(
        private readonly LecteurClasseurPersonnel $lecteur,
        private readonly PersonnelRepository $personnels,
        private readonly ActiviteRepository $activites,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Classeur .xlsx de la situation du personnel')
            ->addOption('date-debut', null, InputOption::VALUE_REQUIRED, 'Début de contrat à défaut de date dans le classeur', '2026-01-01')
            ->addOption('date-fin', null, InputOption::VALUE_REQUIRED, 'Fin de contrat à défaut de date dans le classeur', '2026-12-31')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse le classeur et affiche le bilan sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $fichier */
        $fichier = $input->getArgument('fichier');
        $simulation = (bool) $input->getOption('dry-run');

        try {
            $debutDefaut = new \DateTimeImmutable((string) $input->getOption('date-debut'));
            $fin = (string) $input->getOption('date-fin');
            $finDefaut = '' === $fin ? null : new \DateTimeImmutable($fin);
            $lignes = $this->lecteur->lire($fichier);
        } catch (\RuntimeException|\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (null !== $finDefaut && $finDefaut < $debutDefaut) {
            $io->error('La date de fin par défaut précède la date de début.');

            return Command::FAILURE;
        }

        $io->section(\sprintf('%d agents lus dans %s', \count($lignes), basename($fichier)));

        $mapping = $this->chargerMapping();
        $crees = 0;
        $majs = 0;
        $sansSexe = [];
        $operationsInconnues = [];

        foreach ($lignes as $ligne) {
            if (null === $ligne->sexe) {
                // Le sexe est obligatoire sur la fiche et sert aux
                // désagrégations : on ne le devine pas à partir du prénom.
                $sansSexe[] = \sprintf('%s (ligne %d)', $ligne->nomComplet(), $ligne->ligne);

                continue;
            }

            [$profil, $fonction] = $this->separerCorpsEtPoste($ligne->fonction);
            $activite = $this->activitePour($ligne, $mapping, $operationsInconnues);

            $personnel = $this->personnels->trouverParIdentite($ligne->prenom, $ligne->nom);
            if (null === $personnel) {
                $personnel = new Personnel($ligne->nom, $ligne->prenom, $ligne->sexe, $fonction);
                ++$crees;
                if (!$simulation) {
                    $this->em->persist($personnel);
                }
            } else {
                $personnel->setFonction($fonction);
                $personnel->setSexe($ligne->sexe);
                ++$majs;
            }

            $personnel->setProfil($profil);
            if (null !== $activite) {
                $personnel->setActivite($activite);
            }

            $this->appliquerContrat($personnel, $ligne, $debutDefaut, $finDefaut, $simulation);
        }

        if (!$simulation) {
            $this->em->flush();
        }

        $io->definitionList(
            ['Agents créés' => (string) $crees],
            ['Agents mis à jour' => (string) $majs],
            ['Agents écartés' => (string) \count($sansSexe)],
        );

        if ([] !== $sansSexe) {
            $io->warning(\sprintf(
                "Sexe absent du classeur pour %d agent(s) — non importés, car la désagrégation par sexe en dépend :\n- %s",
                \count($sansSexe),
                implode("\n- ", $sansSexe),
            ));
        }

        if ([] !== $operationsInconnues) {
            $io->warning(\sprintf(
                "Opérations non rattachées au PTBA (agents importés sans opération) :\n- %s\n"
                ."Compléter src/DataFixtures/operations_personnel.php, ou renseigner l'opération depuis la fiche de l'agent.",
                implode("\n- ", array_keys($operationsInconnues)),
            ));
        }

        if ($simulation) {
            $io->note("Simulation (--dry-run) : aucune écriture n'a été faite.");

            return Command::SUCCESS;
        }

        $io->success('Import terminé.');

        return Command::SUCCESS;
    }

    /**
     * Le contrat courant est mis à jour plutôt que doublé : relancer l'import
     * sur l'état du mois suivant ne doit pas empiler des périodes identiques.
     * Un vrai renouvellement, lui, se saisit depuis l'écran RH, qui ajoute
     * une ligne et garde la précédente (§ 10 du CR).
     */
    private function appliquerContrat(
        Personnel $personnel,
        LignePersonnel $ligne,
        \DateTimeImmutable $debutDefaut,
        ?\DateTimeImmutable $finDefaut,
        bool $simulation,
    ): void {
        $debut = $ligne->dateDebut ?? $debutDefaut;
        $fin = $ligne->dateFin ?? $finDefaut;

        $contrat = $personnel->getContratCourant();
        if (null === $contrat) {
            $contrat = new Contrat($personnel, $debut);
            if (!$simulation) {
                $this->em->persist($contrat);
            }
        } else {
            $contrat->setDateDebut($debut);
        }

        $contrat->setDateFin(null !== $fin && $fin < $debut ? null : $fin);
    }

    /**
     * @return array{0: ?string, 1: string} corps (profil) et poste (fonction)
     */
    private function separerCorpsEtPoste(string $fonction): array
    {
        $fonction = trim(preg_replace('/\s+/u', ' ', $fonction) ?? '');
        $position = mb_strpos($fonction, '/');
        if (false === $position) {
            return [null, $fonction];
        }

        $gauche = trim(mb_substr($fonction, 0, $position));
        $droite = trim(mb_substr($fonction, $position + 1));

        if ('' === $droite || !\in_array(mb_strtoupper($gauche), self::CORPS, true)) {
            return [null, $fonction];
        }

        return [mb_strtoupper($gauche), $droite];
    }

    /**
     * @param array<string, string> $mapping
     * @param array<string, true>   $operationsInconnues collecté par référence pour le bilan
     */
    private function activitePour(LignePersonnel $ligne, array $mapping, array &$operationsInconnues): ?Activite
    {
        if (null === $ligne->operation) {
            return null;
        }

        $code = $mapping[$this->normaliser($ligne->operation)] ?? null;
        if (null === $code) {
            $operationsInconnues[$ligne->operation] = true;

            return null;
        }

        $activite = $this->activites->findOneBy(['code' => $code]);
        if (null === $activite) {
            // Le code figure dans la table de rattachement mais pas dans la
            // base : PTBA non chargé, ou code devenu faux. Signalé plutôt que
            // rattaché au hasard.
            $operationsInconnues[\sprintf('%s (code %s absent du PTBA)', $ligne->operation, $code)] = true;
        }

        return $activite;
    }

    /** @return array<string, string> */
    private function chargerMapping(): array
    {
        /** @var array<string, string> $mapping */
        $mapping = require __DIR__.'/../DataFixtures/operations_personnel.php';

        $normalise = [];
        foreach ($mapping as $operation => $code) {
            $normalise[$this->normaliser($operation)] = $code;
        }

        return $normalise;
    }

    private function normaliser(string $valeur): string
    {
        $sansAccent = strtr(
            $valeur,
            ['À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
                'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C'],
        );

        return trim(preg_replace('/\s+/u', ' ', mb_strtoupper($sansAccent, 'UTF-8')) ?? '');
    }
}
