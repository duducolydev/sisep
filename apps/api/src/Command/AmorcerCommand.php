<?php

declare(strict_types=1);

namespace App\Command;

use App\Amorcage\ReferentielApplicatif;
use App\Entity\Permission;
use App\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Amorce une base de production : catalogue des permissions, rôles,
 * paramètres, et premier compte d'administration.
 *
 * C'est le pendant de `app:db:seed` pour une installation réelle. Les deux
 * partagent le même référentiel de droits, mais celle-ci ne crée aucune
 * donnée de démonstration — ni projet fictif, ni comptes d'essai, ni
 * exécution budgétaire simulée. Sur une base destinée à la production,
 * `app:db:seed` ne doit jamais être lancée.
 *
 * Elle ne touche à rien si le référentiel est déjà en place : relancée après
 * une mise à jour, elle se contente de le dire. Ajouter une permission à une
 * base déjà amorcée se fait par migration, pas en rejouant cette commande —
 * elle ne saurait pas distinguer un ajout d'un retrait volontaire.
 */
#[AsCommand(
    name: 'app:amorcer',
    description: "Amorce une base de production : droits, paramètres et compte d'administration",
)]
final class AmorcerCommand extends Command
{
    public function __construct(
        private readonly ReferentielApplicatif $referentiel,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(ADMIN_EMAIL)%')] private readonly string $emailAdministrateur = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'mot-de-passe',
            null,
            InputOption::VALUE_REQUIRED,
            "Mot de passe temporaire du compte d'administration (généré si absent)",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (0 < \count($this->em->getRepository(Permission::class)->findAll())) {
            $io->warning('Le référentiel des droits est déjà en place : rien à faire.');

            return Command::SUCCESS;
        }

        $permissions = $this->referentiel->semerPermissions();
        $roles = $this->referentiel->semerRoles($permissions);
        $nbParametres = $this->referentiel->semerParametres();

        $io->definitionList(
            ['Permissions' => (string) \count($permissions)],
            ['Rôles' => (string) \count($roles)],
            ['Paramètres' => (string) $nbParametres],
        );

        if ('' === $this->emailAdministrateur) {
            $this->em->flush();
            $io->warning(
                "Aucun compte d'administration créé : ADMIN_EMAIL n'est pas renseigné. "
                .'Sans lui, personne ne peut se connecter — renseigner la variable puis relancer sur une base vide.',
            );

            return Command::SUCCESS;
        }

        $motDePasse = $this->motDePasse($input);
        $administrateur = $this->referentiel->creerAdministrateur(
            $this->emailAdministrateur,
            $motDePasse,
            $this->role($roles, 'administrateur'),
        );
        $this->em->flush();

        if (null === $administrateur) {
            $io->warning(\sprintf('Un compte existe déjà pour %s : il n\'a pas été modifié.', $this->emailAdministrateur));

            return Command::SUCCESS;
        }

        // Affiché une fois, à l'écran, jamais écrit sur disque : c'est à
        // l'exploitant de le transmettre et de ne pas le conserver.
        $io->success(\sprintf('Compte d\'administration créé pour %s.', $this->emailAdministrateur));
        $io->block(
            [
                'Mot de passe temporaire : '.$motDePasse,
                "L'application en exigera un nouveau à la première connexion.",
            ],
            'À NOTER MAINTENANT',
            'fg=black;bg=yellow',
            ' ',
            true,
        );

        return Command::SUCCESS;
    }

    private function motDePasse(InputInterface $input): string
    {
        $fourni = $input->getOption('mot-de-passe');
        if (\is_string($fourni) && '' !== trim($fourni)) {
            return trim($fourni);
        }

        // Tiré au hasard plutôt que fixe : un mot de passe d'amorçage
        // identique d'une installation à l'autre finit par circuler.
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Ab'), '=').'!7';
    }

    /** @param array<string, Role> $roles */
    private function role(array $roles, string $code): Role
    {
        return $roles[$code] ?? throw new \RuntimeException(\sprintf('Rôle « %s » absent du référentiel.', $code));
    }
}
