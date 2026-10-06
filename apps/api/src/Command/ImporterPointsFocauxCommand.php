<?php

declare(strict_types=1);

namespace App\Command;

use App\Classeur\Normalisation;
use App\Entity\PointFocal;
use App\Entity\Structure;
use App\Enum\TypeStructure;
use App\PointsFocaux\LecteurContacts;
use App\PointsFocaux\LigneContact;
use App\Repository\ActiviteRepository;
use App\Repository\PointFocalRepository;
use App\Repository\SousActiviteRepository;
use App\Repository\StructureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Charge les points focaux du PHASAOC depuis le classeur de contacts, et
 * rattache chaque ligne du PTBA à la structure qui en répond.
 *
 * À lancer après `app:ptba:importer` : le classeur désigne des personnes
 * pour des codes d'activité, qui doivent déjà exister. Les codes inconnus
 * sont signalés, jamais créés — une activité ne s'invente pas depuis un
 * annuaire.
 *
 * Deux écritures par contact :
 *  - la fiche de point focal, rapprochée par (structure, nom), mise à jour
 *    plutôt que dupliquée quand la même personne répond de plusieurs lignes
 *    du PTBA (c'est fréquent) ;
 *  - la structure responsable de la ligne concernée, que le PTBA seul ne
 *    donne pas.
 *
 * Les structures absentes sont créées : le classeur fait entrer dans le
 * projet des directions sectorielles que le PTBA ne nomme pas. Leur type
 * est déduit de la tutelle — direction de l'ANSD, ou partenaire national —
 * et jamais réécrit sur une structure déjà connue, dont la classification a
 * pu être affinée à la main.
 */
#[AsCommand(
    name: 'app:points-focaux:importer',
    description: 'Importe les points focaux et la structure responsable de chaque ligne du PTBA',
)]
final class ImporterPointsFocauxCommand extends Command
{
    private const SIGLE_ANSD = 'ANSD';

    /** Longueur maximale du sigle en base. */
    private const LONGUEUR_SIGLE = 20;

    /** Mots trop courants pour entrer dans un acronyme construit. */
    private const MOTS_VIDES = ['des', 'les', 'une', 'aux', 'pour', 'sur', 'par', 'avec'];

    /**
     * Objets créés pendant l'exécution, indexés pour être retrouvés sans
     * repasser par la base : tant que le flush n'a pas eu lieu, un
     * findOneBy() ne voit rien de ce que cette exécution vient de créer. Sans
     * ce cache, la même structure se recréerait à chaque ligne qui la cite —
     * et le classeur en cite certaines huit fois.
     *
     * @var array<string, Structure>
     */
    private array $structuresVues = [];

    /** @var array<string, PointFocal> */
    private array $focauxVus = [];

    public function __construct(
        private readonly LecteurContacts $lecteur,
        private readonly StructureRepository $structures,
        private readonly PointFocalRepository $pointsFocaux,
        private readonly ActiviteRepository $activites,
        private readonly SousActiviteRepository $sousActivites,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Classeur .xlsx des contacts des points focaux')
            ->addOption('feuille', null, InputOption::VALUE_REQUIRED, 'Onglet à lire (défaut : le premier)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse le classeur et affiche le bilan sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');

        /** @var string $fichier */
        $fichier = $input->getArgument('fichier');
        $feuille = $input->getOption('feuille');

        try {
            $contacts = $this->lecteur->lire($fichier, \is_string($feuille) && '' !== $feuille ? $feuille : null);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section(\sprintf('%d contact(s) lus dans %s', \count($contacts), basename($fichier)));

        $structuresCreees = 0;
        $focauxCrees = 0;
        $focauxMaj = 0;
        $rattachements = 0;
        $codesInconnus = [];

        foreach ($contacts as $contact) {
            $structure = $this->structure($contact->structure, $structuresCreees, $simulation);
            $this->pointFocal($contact, $structure, $focauxCrees, $focauxMaj, $simulation);

            if ($this->rattacher($contact, $structure)) {
                ++$rattachements;
            } else {
                $codesInconnus[$contact->code] = $contact->libelleActivite;
            }
        }

        if (!$simulation) {
            $this->em->flush();
        }

        $io->definitionList(
            ['Structures créées' => (string) $structuresCreees],
            ['Points focaux créés' => (string) $focauxCrees],
            ['Points focaux mis à jour' => (string) $focauxMaj],
            ['Lignes du PTBA rattachées' => (string) $rattachements],
        );

        if ([] !== $codesInconnus) {
            $io->warning(\sprintf(
                "%d code(s) du classeur sans correspondance dans le PTBA chargé — point focal créé, mais aucune ligne rattachée :\n- %s\n"
                .'Vérifier que le PTBA de la bonne année est importé.',
                \count($codesInconnus),
                implode("\n- ", array_map(
                    static fn (string $code, string $libelle) => \sprintf('%s — %s', $code, $libelle),
                    array_keys($codesInconnus),
                    $codesInconnus,
                )),
            ));
        }

        if ($simulation) {
            $io->note("Simulation (--dry-run) : aucune écriture n'a été faite.");

            return Command::SUCCESS;
        }

        $io->success('Points focaux importés.');

        return Command::SUCCESS;
    }

    private function structure(string $intitule, int &$creees, bool $simulation): Structure
    {
        $sigle = $this->sigle($intitule);
        if (isset($this->structuresVues[$sigle])) {
            return $this->structuresVues[$sigle];
        }

        $structure = $this->structures->findOneBy(['sigle' => $sigle]);
        if (null === $structure) {
            $structure = new Structure($sigle, $intitule, $this->type($intitule));
            if (!$simulation) {
                $this->em->persist($structure);
            }
            ++$creees;
        }

        return $this->structuresVues[$sigle] = $structure;
    }

    private function pointFocal(LigneContact $contact, Structure $structure, int &$crees, int &$majs, bool $simulation): void
    {
        $cle = $structure->getSigle().'|'.Normalisation::cle($contact->nomComplet);
        $existant = $this->focauxVus[$cle] ?? null;

        if (null === $existant) {
            foreach ($this->pointsFocaux->findBy(['structure' => $structure]) as $candidat) {
                if (Normalisation::cle($candidat->getNomComplet()) === Normalisation::cle($contact->nomComplet)) {
                    $existant = $candidat;
                    break;
                }
            }
        }

        if (null === $existant) {
            // Faute de titre dans le classeur, la fonction dit ce que la
            // personne fait pour le projet : elle est le point focal de sa
            // structure. Un titre explicite, quand le classeur en donne un,
            // l'emporte.
            $existant = new PointFocal($structure, $contact->nomComplet, $contact->fonction ?? 'Point focal PHASAOC');
            if (!$simulation) {
                $this->em->persist($existant);
            }
            ++$crees;
        } else {
            if (null !== $contact->fonction) {
                $existant->setFonction($contact->fonction);
            }
            ++$majs;
        }

        $this->focauxVus[$cle] = $existant;

        // Le classeur fait foi sur les coordonnées : c'est sa raison d'être.
        if (null !== $contact->telephone) {
            $existant->setTelephone($contact->telephone);
        }
        if (null !== $contact->email) {
            $existant->setEmail($contact->email);
        }
    }

    /**
     * Rattache la structure à la ligne du PTBA désignée par le code.
     *
     * Le classeur désigne des lignes à quatre niveaux (« 1.2.2.1 »), qui sont
     * des sous-activités ; l'activité porteuse (« 1.2.2 ») reçoit la même
     * structure tant qu'elle porte encore celle posée par défaut à l'import
     * du PTBA — une activité dont plusieurs sous-activités relèvent de
     * directions différentes garde donc la première rencontrée, ce que le
     * bilan ne cache pas.
     */
    private function rattacher(LigneContact $contact, Structure $structure): bool
    {
        $activite = $this->activites->findOneBy(['code' => $contact->code]);
        if (null !== $activite) {
            $activite->setStructureResponsable($structure);

            return true;
        }

        $position = strrpos($contact->code, '.');
        $codeParent = false === $position ? null : substr($contact->code, 0, $position);
        $parente = null !== $codeParent ? $this->activites->findOneBy(['code' => $codeParent]) : null;
        if (null === $parente) {
            return false;
        }

        $libelle = Normalisation::cle($contact->libelleActivite);
        foreach ($this->sousActivites->findBy(['activite' => $parente]) as $sousActivite) {
            if (Normalisation::cle($sousActivite->getLibelle()) === $libelle) {
                $sousActivite->setStructureResponsable($structure);
            }
        }

        if (self::SIGLE_ANSD === $parente->getStructureResponsable()->getSigle()) {
            $parente->setStructureResponsable($structure);
        }

        return true;
    }

    /**
     * Sigle d'une structure décrite « Tutelle / Direction » : la direction
     * quand elle tient en base, la tutelle sinon, et en dernier recours un
     * acronyme construit sur ses initiales.
     */
    private function sigle(string $intitule): string
    {
        $parties = array_map('trim', explode('/', $intitule));
        $tutelle = $parties[0];
        $direction = \count($parties) > 1 ? (string) end($parties) : '';

        // « DAGRH &Direction Général & ENSAE » : plusieurs services dans une
        // cellule, on retient le premier, seul rattachement sûr.
        $candidat = '' !== $direction ? trim(explode('&', $direction)[0]) : '';

        if ('' !== $candidat && mb_strlen($candidat) <= self::LONGUEUR_SIGLE) {
            return $candidat;
        }
        if (mb_strlen($tutelle) <= self::LONGUEUR_SIGLE) {
            return $tutelle;
        }

        return mb_substr($this->acronyme('' !== $candidat ? $candidat : $tutelle), 0, self::LONGUEUR_SIGLE);
    }

    private function acronyme(string $valeur): string
    {
        $mots = preg_split("/[\s'’\-,]+/u", Normalisation::cle($valeur)) ?: [];
        $initiales = '';
        foreach ($mots as $mot) {
            if (mb_strlen($mot) >= 3 && !\in_array(mb_strtolower($mot), self::MOTS_VIDES, true)) {
                $initiales .= mb_substr($mot, 0, 1);
            }
        }

        return '' !== $initiales ? $initiales : mb_substr(Normalisation::cle($valeur), 0, self::LONGUEUR_SIGLE);
    }

    private function type(string $intitule): TypeStructure
    {
        $tutelle = Normalisation::cle(explode('/', $intitule)[0]);

        return self::SIGLE_ANSD === $tutelle ? TypeStructure::DIRECTION_ANSD : TypeStructure::PARTENAIRE_NATIONAL;
    }
}
