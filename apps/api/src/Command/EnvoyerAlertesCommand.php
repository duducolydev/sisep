<?php

declare(strict_types=1);

namespace App\Command;

use App\Courriel\ServiceCourriel;
use App\Entity\RapportPeriodique;
use App\Enum\StatutRapport;
use App\Rapport\DestinatairesRelanceResolver;
use App\Repository\ContratRepository;
use App\Repository\ParametreRepository;
use App\Repository\RapportPeriodiqueRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Alertes planifiées (§ 10 et § 13 du CR du 22/09/2026) : relance des
 * rapports périodiques en retard, et échéances de contrat du personnel
 * technique. Point d'exécution unique, à brancher sur le cron du serveur de
 * production (documenté en exploitation).
 *
 * Idempotence, parce qu'un cron quotidien passe tous les jours sur les mêmes
 * données :
 *  - une relance de rapport n'est renvoyée qu'après un délai minimal,
 *    mesuré sur `derniere_relance_le`, le même horodatage que la relance
 *    manuelle de l'écran de pilotage ;
 *  - une échéance de contrat n'alerte qu'aux paliers configurés (30, 15, 7
 *    et 0 jours restants par défaut), donc quatre fois au plus sur la vie
 *    d'un contrat, sans colonne supplémentaire à maintenir.
 *
 * Les deux familles d'alertes sont indépendantes : un échec d'envoi n'arrête
 * pas la commande, ServiceCourriel journalisant et renvoyant false. Le code
 * de retour reste SUCCESS — un cron qui échoue sur une panne SMTP ne dit
 * rien d'utile, et le bilan affiché distingue déjà ce qui est parti de ce
 * qui n'a pas pu partir.
 */
#[AsCommand(
    name: 'app:alertes:envoyer',
    description: 'Envoie les alertes planifiées : relances de reporting en retard et échéances de contrat',
)]
final class EnvoyerAlertesCommand extends Command
{
    private const CODE_DESTINATAIRES = 'alertes.destinataires';
    private const CODE_CONTRAT_JOURS = 'alertes.contrat_jours';
    private const CODE_INTERVALLE_RELANCE = 'alertes.relance_intervalle_jours';

    private const DEFAUT_CONTRAT_JOURS = '30,15,7,0';
    private const DEFAUT_INTERVALLE_RELANCE = 7;

    /** Rôles destinataires à défaut d'adresses configurées : ceux qui pilotent le projet. */
    private const ROLES_PILOTAGE = ['coordonnateur', 'responsable_suivi_evaluation'];

    public function __construct(
        private readonly RapportPeriodiqueRepository $rapports,
        private readonly ContratRepository $contrats,
        private readonly ParametreRepository $parametres,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly DestinatairesRelanceResolver $destinatairesRelance,
        private readonly ServiceCourriel $courriels,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait envoyé sans envoyer de courriel ni horodater de relance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $io->section('Relances de reporting');
        [$relances, $courrielsRelance, $sansDestinataire] = $this->relancerRapports($io, $maintenant, $simulation);

        $io->section('Échéances de contrat');
        $courrielsEcheances = $this->alerterEcheances($io, $maintenant, $simulation);

        if (!$simulation) {
            $this->em->flush();
        }

        $io->definitionList(
            ['Rapports relancés' => (string) $relances],
            ['Courriels de relance' => (string) $courrielsRelance],
            ['Structures sans adresse' => (string) $sansDestinataire],
            ['Courriels d\'échéance' => (string) $courrielsEcheances],
        );

        if ($simulation) {
            $io->note('Simulation (--dry-run) : aucun courriel envoyé, aucune relance horodatée.');
        }

        return Command::SUCCESS;
    }

    /** @return array{0: int, 1: int, 2: int} relances, courriels, structures sans adresse */
    private function relancerRapports(SymfonyStyle $io, \DateTimeImmutable $maintenant, bool $simulation): array
    {
        $intervalle = max(1, $this->parametres->valeurEntiere(self::CODE_INTERVALLE_RELANCE, self::DEFAUT_INTERVALLE_RELANCE));
        $relances = 0;
        $courriels = 0;
        $sansDestinataire = 0;

        foreach ($this->rapports->findBy(['statut' => StatutRapport::ATTENDU]) as $rapport) {
            if (!$rapport->estEnRetard($maintenant) || !$this->relanceDue($rapport, $maintenant, $intervalle)) {
                continue;
            }

            $structure = $rapport->getStructure();
            $adresses = $this->destinatairesRelance->pour($structure);
            $io->text(\sprintf(
                '  %s — %s %d, échéance %s%s',
                $structure->getSigle(),
                $rapport->getPeriode()->value,
                $rapport->getExercice()->getAnnee(),
                $rapport->getDateEcheance()->format('Y-m-d'),
                [] === $adresses ? ' (aucune adresse)' : ' → '.implode(', ', $adresses),
            ));

            ++$relances;
            if ([] === $adresses) {
                ++$sansDestinataire;

                continue;
            }

            if ($simulation) {
                continue;
            }

            // Horodatée même si aucun courriel ne part : sans cela, une
            // panne SMTP ferait réessayer chaque jour sur les mêmes lignes.
            $rapport->relancer($maintenant);
            foreach ($adresses as $adresse) {
                if ($this->courriels->relanceRapport($rapport, $adresse, $maintenant)) {
                    ++$courriels;
                }
            }
        }

        if (0 === $relances) {
            $io->text('  Aucun rapport à relancer.');
        }

        return [$relances, $courriels, $sansDestinataire];
    }

    private function alerterEcheances(SymfonyStyle $io, \DateTimeImmutable $maintenant, bool $simulation): int
    {
        $paliers = $this->paliers();
        if ([] === $paliers) {
            $io->text('  Aucun palier configuré — rien à signaler.');

            return 0;
        }

        // Une seule lecture, bornée au palier le plus lointain, puis filtrage
        // sur les paliers exacts : le dépôt n'a pas besoin d'une requête par
        // palier. Les contrats déjà échus (jours négatifs) ne sont pas
        // rappelés indéfiniment — seul le palier 0 les signale, le jour même.
        $limite = $maintenant->modify(\sprintf('+%d days', max($paliers)));
        $lignes = [];
        foreach ($this->contrats->echeantsAvant($limite) as $contrat) {
            $personnel = $contrat->getPersonnel();
            if ($personnel->estSupprime()) {
                continue;
            }
            $jours = $contrat->joursAvantEcheance($maintenant);
            if (null === $jours || !\in_array($jours, $paliers, true)) {
                continue;
            }

            $lignes[] = [
                'nomComplet' => $personnel->getNomComplet(),
                'fonction' => $personnel->getFonction(),
                'dateFin' => $contrat->getDateFin()?->format('Y-m-d') ?? '',
                'jours' => $jours,
            ];
        }

        if ([] === $lignes) {
            $io->text('  Aucune échéance aux paliers configurés.');

            return 0;
        }

        $destinataires = $this->destinatairesAlertes();
        foreach ($lignes as $ligne) {
            $io->text(\sprintf('  %s (%s) — fin %s, J%+d', $ligne['nomComplet'], $ligne['fonction'], $ligne['dateFin'], $ligne['jours']));
        }

        if ([] === $destinataires) {
            $io->warning(\sprintf(
                '%d échéance(s) détectée(s) mais aucun destinataire : renseigner le paramètre « %s » ou attribuer un rôle de pilotage à un compte.',
                \count($lignes),
                self::CODE_DESTINATAIRES,
            ));

            return 0;
        }

        $io->text('  Destinataires : '.implode(', ', $destinataires));
        if ($simulation) {
            return 0;
        }

        $courriels = 0;
        foreach ($destinataires as $destinataire) {
            if ($this->courriels->echeancesContrats($destinataire, $lignes)) {
                ++$courriels;
            }
        }

        return $courriels;
    }

    private function relanceDue(RapportPeriodique $rapport, \DateTimeImmutable $maintenant, int $intervalleJours): bool
    {
        $derniere = $rapport->getDerniereRelanceLe();
        if (null === $derniere) {
            return true;
        }

        return $derniere->modify(\sprintf('+%d days', $intervalleJours)) <= $maintenant;
    }

    /** @return list<int> */
    private function paliers(): array
    {
        $brut = $this->parametres->valeurTexte(self::CODE_CONTRAT_JOURS, self::DEFAUT_CONTRAT_JOURS);
        $paliers = [];
        foreach (explode(',', $brut) as $morceau) {
            $morceau = trim($morceau);
            if ('' !== $morceau && ctype_digit($morceau)) {
                $paliers[] = (int) $morceau;
            }
        }

        return array_values(array_unique($paliers));
    }

    /**
     * Adresses configurées si le paramètre est renseigné, sinon les comptes
     * qui pilotent le projet : une alerte sans destinataire ne sert à rien,
     * et la configuration ne doit pas être un préalable au fonctionnement.
     *
     * @return list<string>
     */
    private function destinatairesAlertes(): array
    {
        $configurees = [];
        foreach (explode(',', $this->parametres->valeurTexte(self::CODE_DESTINATAIRES)) as $adresse) {
            $adresse = trim($adresse);
            if ('' !== $adresse && false !== filter_var($adresse, \FILTER_VALIDATE_EMAIL)) {
                $configurees[] = $adresse;
            }
        }
        if ([] !== $configurees) {
            return array_values(array_unique($configurees));
        }

        $adresses = [];
        foreach (self::ROLES_PILOTAGE as $code) {
            foreach ($this->utilisateurs->listerParRole($code) as $utilisateur) {
                $adresses[] = $utilisateur->getEmail();
            }
        }

        return array_values(array_unique($adresses));
    }
}
