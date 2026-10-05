<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RapportPeriodique;
use App\Enum\PeriodeTrimestre;
use App\Repository\ExerciceRepository;
use App\Repository\ParametreRepository;
use App\Repository\ProjetRepository;
use App\Repository\RapportPeriodiqueRepository;
use App\Repository\StructureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * § 5.5 du brief : "génération automatique des lignes rapports_periodiques à
 * l'ouverture de chaque trimestre, pour chaque structure active, avec
 * statut='attendu' et une échéance paramétrable (par défaut J+15)". Le brief
 * parle d'une tâche planifiée quotidienne — cette commande est le point
 * d'exécution à brancher sur un cron externe (documenté en exploitation,
 * § "sauvegardes et documentation" du P7) ; idempotente, elle peut tourner
 * chaque jour sans créer de doublons (contrainte unique structure×exercice×
 * période, et vérification préalable).
 */
#[AsCommand(name: 'app:rapports:generer', description: 'Génère les lignes de reporting trimestriel attendues pour le trimestre courant')]
final class GenererRapportsCommand extends Command
{
    private const CODE_ECHEANCE_JOURS = 'rapport.echeance_jours';
    private const DEFAUT_ECHEANCE_JOURS = 15;

    public function __construct(
        private readonly ProjetRepository $projets,
        private readonly ExerciceRepository $exercices,
        private readonly StructureRepository $structures,
        private readonly RapportPeriodiqueRepository $rapports,
        private readonly ParametreRepository $parametres,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projet = $this->projets->findOneBy([]);
        if (null === $projet) {
            $io->warning('Aucun projet en base — rien à générer.');

            return Command::SUCCESS;
        }

        $aujourdhui = new \DateTimeImmutable('today');
        $periode = $this->periodeCourante($aujourdhui);
        [, $finTrimestre] = $this->bornesTrimestre($aujourdhui, $periode);

        $exercice = $this->exercices->trouverParAnnee($projet, (int) $aujourdhui->format('Y'));
        if (null === $exercice) {
            $io->warning(\sprintf('Aucun exercice %d pour le projet — rien à générer.', (int) $aujourdhui->format('Y')));

            return Command::SUCCESS;
        }

        $echeanceJours = $this->parametres->valeurEntiere(self::CODE_ECHEANCE_JOURS, self::DEFAUT_ECHEANCE_JOURS);
        $dateEcheance = $finTrimestre->modify(\sprintf('+%d days', $echeanceJours));

        $crees = 0;
        foreach ($this->structures->listerActives() as $structure) {
            if (null !== $this->rapports->trouverPour($structure, $exercice, $periode)) {
                continue;
            }

            $rapport = new RapportPeriodique($structure, $exercice, $periode, $dateEcheance);
            $this->em->persist($rapport);
            ++$crees;
        }

        $this->em->flush();

        $io->success(\sprintf('%d rapport(s) %s %d généré(s) (échéance %s).', $crees, $periode->value, $exercice->getAnnee(), $dateEcheance->format('Y-m-d')));

        return Command::SUCCESS;
    }

    private function periodeCourante(\DateTimeImmutable $date): PeriodeTrimestre
    {
        $mois = (int) $date->format('n');

        return match (true) {
            $mois <= 3 => PeriodeTrimestre::T1,
            $mois <= 6 => PeriodeTrimestre::T2,
            $mois <= 9 => PeriodeTrimestre::T3,
            default => PeriodeTrimestre::T4,
        };
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function bornesTrimestre(\DateTimeImmutable $date, PeriodeTrimestre $periode): array
    {
        $annee = (int) $date->format('Y');
        $premierMois = match ($periode) {
            PeriodeTrimestre::T1 => 1,
            PeriodeTrimestre::T2 => 4,
            PeriodeTrimestre::T3 => 7,
            PeriodeTrimestre::T4 => 10,
        };

        $debut = new \DateTimeImmutable(\sprintf('%d-%02d-01', $annee, $premierMois));
        $fin = $debut->modify('+2 months')->modify('last day of this month');

        return [$debut, $fin];
    }
}
