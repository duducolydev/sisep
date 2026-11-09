<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\JournalActivite;
use App\Entity\PieceJointe;
use App\Entity\Utilisateur;
use App\Enum\StatutEcriture;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Efface les écritures budgétaires d'essai saisies avant la mise en service.
 *
 * Dérogation ponctuelle, décidée le 09/11/2026, à la suppression logique et à
 * l'intégrité du journal : les essais du 13/10/2026 ne doivent ni peser sur le
 * budget ni encombrer le fil d'audit au démarrage réel. Une seule ligne de
 * journal en garde la trace ; le détail d'origine reste dans la sauvegarde
 * faite avant l'exécution.
 *
 * Le périmètre se déduit du journal : les dotations et engagements saisis par
 * une personne jusqu'à la date donnée. Un import n'a pas d'auteur au journal,
 * les dotations du PTBA restent donc hors d'atteinte. Les nombres attendus sont
 * à annoncer et rien n'est écrit sans --force.
 *
 * Commande à usage unique : à retirer du dépôt une fois exécutée.
 */
#[AsCommand(name: 'app:budget:purger-essais', description: 'Efface les écritures budgétaires d\'essai antérieures à la mise en service')]
final class PurgerEssaisBudgetCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly TagAwareCacheInterface $arbreCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('jusqu-au', null, InputOption::VALUE_REQUIRED, 'Dernier jour de saisie des essais (AAAA-MM-JJ, UTC)')
            ->addOption('dotations', null, InputOption::VALUE_REQUIRED, 'Nombre de dotations attendu')
            ->addOption('engagements', null, InputOption::VALUE_REQUIRED, 'Nombre d\'engagements attendu')
            ->addOption('auteur', null, InputOption::VALUE_REQUIRED, 'Adresse du compte qui répond de la purge dans le journal')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Exécute la purge ; sans cette option, simple simulation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $jusquAu = (string) $input->getOption('jusqu-au');
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $jusquAu, new \DateTimeZone('UTC'));
        if (false === $jour || $jour->format('Y-m-d') !== $jusquAu) {
            $io->error('--jusqu-au doit être une date au format AAAA-MM-JJ.');

            return Command::FAILURE;
        }
        $limite = $jour->modify('+1 day');

        $dotationsAttendues = filter_var($input->getOption('dotations'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $engagementsAttendus = filter_var($input->getOption('engagements'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (false === $dotationsAttendues || false === $engagementsAttendus) {
            $io->error('--dotations et --engagements doivent annoncer les nombres attendus.');

            return Command::FAILURE;
        }

        $adresse = $input->getOption('auteur');
        $auteur = \is_string($adresse) ? $this->utilisateurs->findOneBy(['email' => trim($adresse)]) : null;
        if (null === $auteur) {
            $io->error('--auteur doit désigner un compte existant : la purge doit avoir un responsable dans le journal.');

            return Command::FAILURE;
        }

        [$dotations, $engagements] = $this->perimetre($limite);
        $this->afficher($io, $dotations, $engagements);

        $motif = $this->motifDeRefus($dotations, $engagements, $dotationsAttendues, $engagementsAttendus, $limite);
        if (null !== $motif) {
            $io->error($motif);

            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            $io->note('Simulation : purge possible, rien n\'a été écrit. Relancer avec --force pour l\'exécuter.');

            return Command::SUCCESS;
        }

        $motif = $this->em->wrapInTransaction(function () use ($limite, $dotationsAttendues, $engagementsAttendus, $auteur, $jusquAu): ?string {
            // Le périmètre est relu sous verrou : une saisie arrivée depuis
            // la simulation doit faire échouer le contrôle des nombres.
            [$dotations, $engagements] = $this->perimetre($limite);
            foreach ([...$dotations, ...$engagements] as $ecriture) {
                $this->em->lock($ecriture, LockMode::PESSIMISTIC_WRITE);
            }

            $motif = $this->motifDeRefus($dotations, $engagements, $dotationsAttendues, $engagementsAttendus, $limite);
            if (null === $motif) {
                $this->purger($dotations, $engagements, $auteur, $jusquAu);
            }

            return $motif;
        });

        if (null !== $motif) {
            $io->error($motif);

            return Command::FAILURE;
        }

        // Les suppressions en DQL échappent à ArbreCacheInvalidationListener.
        $this->arbreCache->invalidateTags(['arbre']);

        $io->success('Écritures d\'essai purgées. Le journal en garde une seule ligne de trace.');

        return Command::SUCCESS;
    }

    /** @return array{0: list<Dotation>, 1: list<Engagement>} */
    private function perimetre(\DateTimeImmutable $limite): array
    {
        /** @var list<Dotation> $dotations */
        $dotations = $this->em->getRepository(Dotation::class)->findBy(['id' => $this->identifiantsSaisis('dotation', $limite)], ['id' => 'ASC']);
        /** @var list<Engagement> $engagements */
        $engagements = $this->em->getRepository(Engagement::class)->findBy(['id' => $this->identifiantsSaisis('engagement', $limite)], ['id' => 'ASC']);

        return [$dotations, $engagements];
    }

    /** @return list<int> */
    private function identifiantsSaisis(string $type, \DateTimeImmutable $limite): array
    {
        $resultat = $this->em->createQuery(
            'SELECT j.entiteId FROM '.JournalActivite::class.' j
             WHERE j.entiteType = :type AND j.action = :action
               AND j.utilisateur IS NOT NULL AND j.creeLe < :limite',
        )
            ->setParameter('type', $type)
            ->setParameter('action', 'creation')
            ->setParameter('limite', $limite)
            ->getSingleColumnResult();

        return array_map(intval(...), $resultat);
    }

    /**
     * @param list<Dotation>   $dotations
     * @param list<Engagement> $engagements
     */
    private function motifDeRefus(array $dotations, array $engagements, int $dotationsAttendues, int $engagementsAttendus, \DateTimeImmutable $limite): ?string
    {
        if ([] === $dotations && [] === $engagements) {
            return 'Aucune écriture d\'essai à purger sur cette période.';
        }

        if (\count($dotations) !== $dotationsAttendues || \count($engagements) !== $engagementsAttendus) {
            return \sprintf(
                'Périmètre inattendu : %d dotation(s) et %d engagement(s) trouvés, %d et %d annoncés.',
                \count($dotations),
                \count($engagements),
                $dotationsAttendues,
                $engagementsAttendus,
            );
        }

        foreach ($engagements as $engagement) {
            if (StatutEcriture::ANNULE !== $engagement->getStatut()) {
                return \sprintf('L\'engagement #%d est au statut « %s » : seul un engagement annulé peut être purgé.', $engagement->getId(), $engagement->getStatut()->value);
            }
        }

        foreach ([...$dotations, ...$engagements] as $ecriture) {
            if (!$ecriture->getExercice()->estOuvert()) {
                return 'Une de ces écritures porte sur un exercice clôturé : aucune écriture budgétaire n\'y est plus acceptée.';
            }
        }

        $idsDotations = $this->identifiants($dotations);
        $idsEngagements = $this->identifiants($engagements);

        if ($this->compterJournal('dotation', $idsDotations, $limite) + $this->compterJournal('engagement', $idsEngagements, $limite) > 0) {
            return 'Une de ces écritures a été modifiée après la date limite : ce n\'est plus un simple essai.';
        }

        if ([] !== $idsEngagements && $this->compter('SELECT COUNT(d.id) FROM '.Depense::class.' d WHERE d.engagement IN (:ids)', ['ids' => $idsEngagements]) > 0) {
            return 'Un de ces engagements porte des dépenses, même annulées : à examiner avant toute purge.';
        }

        if ($this->compterPiecesJointes('dotation', $idsDotations) + $this->compterPiecesJointes('engagement', $idsEngagements) > 0) {
            return 'Une de ces écritures porte un justificatif : à examiner avant toute purge.';
        }

        foreach ($dotations as $dotation) {
            $motif = $this->motifLigneOccupee($dotation);
            if (null !== $motif) {
                return $motif;
            }
        }

        return null;
    }

    /** Une dotation ne s'efface pas sous une écriture vivante : celle-ci perdrait son plafond. */
    private function motifLigneOccupee(Dotation $dotation): ?string
    {
        $champ = null !== $dotation->getActivite() ? 'activite' : 'sousActivite';
        $parametres = [
            'cible' => $dotation->getCible(),
            'exercice' => $dotation->getExercice(),
            'annule' => StatutEcriture::ANNULE,
        ];

        $engagements = $this->compter(
            'SELECT COUNT(e.id) FROM '.Engagement::class." e
             WHERE e.{$champ} = :cible AND e.exercice = :exercice AND e.statut <> :annule AND e.supprimeLe IS NULL",
            $parametres,
        );
        if ($engagements > 0) {
            return \sprintf('La ligne de la dotation #%d porte des engagements en brouillon ou validés.', $dotation->getId());
        }

        $depenses = $this->compter(
            'SELECT COUNT(d.id) FROM '.Depense::class." d JOIN d.engagement e
             WHERE e.{$champ} = :cible AND e.exercice = :exercice AND d.statut <> :annule AND d.supprimeLe IS NULL",
            $parametres,
        );
        if ($depenses > 0) {
            return \sprintf('La ligne de la dotation #%d porte des dépenses en brouillon ou validées.', $dotation->getId());
        }

        return null;
    }

    /**
     * @param list<Dotation>   $dotations
     * @param list<Engagement> $engagements
     */
    private function purger(array $dotations, array $engagements, Utilisateur $auteur, string $jusquAu): void
    {
        $idsDotations = $this->identifiants($dotations);
        $idsEngagements = $this->identifiants($engagements);

        $lignesJournal = $this->compterJournal('dotation', $idsDotations) + $this->compterJournal('engagement', $idsEngagements);

        $this->em->persist(new JournalActivite(
            $auteur,
            'purge',
            'budget',
            null,
            [
                'motif' => 'Écritures d\'essai antérieures à la mise en service',
                'saisiesJusquAu' => $jusquAu,
                'dotations' => \count($dotations),
                'montantDotations' => array_sum(array_map(static fn (Dotation $d) => $d->getMontant()->versFcfa(), $dotations)),
                'identifiantsDotations' => $idsDotations,
                'engagements' => \count($engagements),
                'montantEngagements' => array_sum(array_map(static fn (Engagement $e) => $e->getMontant()->versFcfa(), $engagements)),
                'identifiantsEngagements' => $idsEngagements,
                'lignesJournalPurgees' => $lignesJournal,
            ],
            null,
            '0.0.0.0',
            'console',
        ));
        $this->em->flush();

        // En DQL et non par remove() : le journal prendrait sinon une ligne de
        // suppression par écriture, là où une seule trace est voulue.
        foreach (['dotation' => $idsDotations, 'engagement' => $idsEngagements] as $type => $ids) {
            if ([] === $ids) {
                continue;
            }
            $this->em->createQuery('DELETE FROM '.JournalActivite::class.' j WHERE j.entiteType = :type AND j.entiteId IN (:ids)')
                ->setParameter('type', $type)
                ->setParameter('ids', $ids)
                ->execute();
        }
        if ([] !== $idsDotations) {
            $this->em->createQuery('DELETE FROM '.Dotation::class.' d WHERE d.id IN (:ids)')->setParameter('ids', $idsDotations)->execute();
        }
        if ([] !== $idsEngagements) {
            $this->em->createQuery('DELETE FROM '.Engagement::class.' e WHERE e.id IN (:ids)')->setParameter('ids', $idsEngagements)->execute();
        }

        // Les entités effacées ne doivent pas rester gérées par Doctrine.
        $this->em->clear();
    }

    /**
     * @param list<Dotation|Engagement> $ecritures
     *
     * @return list<int>
     */
    private function identifiants(array $ecritures): array
    {
        return array_map(static fn (Dotation|Engagement $e) => (int) $e->getId(), $ecritures);
    }

    /**
     * Lignes de journal d'écritures données, éventuellement limitées à celles
     * écrites à partir d'une date.
     *
     * @param list<int> $ids
     */
    private function compterJournal(string $type, array $ids, ?\DateTimeImmutable $depuis = null): int
    {
        if ([] === $ids) {
            return 0;
        }

        $dql = 'SELECT COUNT(j.id) FROM '.JournalActivite::class.' j WHERE j.entiteType = :type AND j.entiteId IN (:ids)';
        $parametres = ['type' => $type, 'ids' => $ids];
        if (null !== $depuis) {
            $dql .= ' AND j.creeLe >= :depuis';
            $parametres['depuis'] = $depuis;
        }

        return $this->compter($dql, $parametres);
    }

    /** @param list<int> $ids */
    private function compterPiecesJointes(string $type, array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return $this->compter(
            'SELECT COUNT(p.id) FROM '.PieceJointe::class.' p WHERE p.entiteType = :type AND p.entiteId IN (:ids)',
            ['type' => $type, 'ids' => $ids],
        );
    }

    /** @param array<string, mixed> $parametres */
    private function compter(string $dql, array $parametres): int
    {
        $requete = $this->em->createQuery($dql);
        foreach ($parametres as $nom => $valeur) {
            $requete->setParameter($nom, $valeur);
        }

        return (int) $requete->getSingleScalarResult();
    }

    /**
     * @param list<Dotation>   $dotations
     * @param list<Engagement> $engagements
     */
    private function afficher(SymfonyStyle $io, array $dotations, array $engagements): void
    {
        $lignes = [];
        foreach ($dotations as $dotation) {
            $lignes[] = ['Dotation', $dotation->getId(), $dotation->getActiviteParente()->getCode(), $dotation->getSousActivite()?->getLibelle() ?? '—', $dotation->getMontant()->formater(), '—'];
        }
        foreach ($engagements as $engagement) {
            $lignes[] = ['Engagement', $engagement->getId(), $engagement->getActiviteParente()->getCode(), $engagement->getSousActivite()?->getLibelle() ?? '—', $engagement->getMontant()->formater(), $engagement->getStatut()->value];
        }

        $io->table(['Écriture', 'Id', 'Activité', 'Ligne', 'Montant', 'Statut'], $lignes);
        $io->text(\sprintf(
            'Lignes de journal concernées : %d',
            $this->compterJournal('dotation', $this->identifiants($dotations)) + $this->compterJournal('engagement', $this->identifiants($engagements)),
        ));
    }
}
