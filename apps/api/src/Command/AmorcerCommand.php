<?php

declare(strict_types=1);

namespace App\Command;

use App\Amorcage\ReferentielApplicatif;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Utilisateur;
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
 * Deux parties indépendantes, et c'est ce qui la rend sûre à relancer :
 *  - le référentiel des droits n'est semé que sur une base vierge. Ajouter
 *    une permission à une base déjà amorcée se fait par migration — la
 *    commande ne saurait pas distinguer un ajout d'un retrait volontaire ;
 *  - le compte d'administration est créé s'il manque, à chaque passage.
 *    Une première exécution sans ADMIN_EMAIL ne condamne donc plus la base :
 *    on renseigne la variable et on relance.
 *
 * `--reinitialiser-mot-de-passe` donne un nouveau mot de passe temporaire au
 * compte d'administration existant. C'est le recours quand celui affiché au
 * premier amorçage a été perdu : il n'est écrit nulle part, par conception.
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
        $this
            ->addOption(
                'mot-de-passe',
                null,
                InputOption::VALUE_REQUIRED,
                "Mot de passe temporaire du compte d'administration (généré si absent)",
            )
            ->addOption(
                'reinitialiser-mot-de-passe',
                null,
                InputOption::VALUE_NONE,
                "Donne un nouveau mot de passe temporaire au compte d'administration existant",
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->semerReferentielSiVierge($io);
        $nbGabarits = $this->referentiel->semerGabaritsSiCatalogueVide();
        if ($nbGabarits > 0) {
            $this->em->flush();
            $io->text(\sprintf("%d modèles d'étapes de départ créés (catalogue vide).", $nbGabarits));
        }

        if ('' === $this->emailAdministrateur) {
            $this->em->flush();
            $io->warning(
                "Aucun compte d'administration : ADMIN_EMAIL n'est pas renseigné. "
                .'Sans lui, personne ne peut se connecter — renseigner la variable puis relancer cette commande.',
            );

            return Command::SUCCESS;
        }

        $compte = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $this->emailAdministrateur]);
        $motDePasse = $this->motDePasse($input);

        if (null === $compte) {
            $this->referentiel->creerAdministrateur($this->emailAdministrateur, $motDePasse, $this->roleAdministrateur());
            $this->em->flush();
            $io->success(\sprintf("Compte d'administration créé pour %s.", $this->emailAdministrateur));
            $this->afficherMotDePasse($io, $motDePasse);

            return Command::SUCCESS;
        }

        if (!$input->getOption('reinitialiser-mot-de-passe')) {
            $this->em->flush();
            $io->note(\sprintf(
                "Le compte %s existe déjà : il n'a pas été modifié. "
                .'Mot de passe perdu ? Relancer avec --reinitialiser-mot-de-passe.',
                $this->emailAdministrateur,
            ));

            return Command::SUCCESS;
        }

        $this->referentiel->reinitialiserMotDePasse($compte, $motDePasse);
        $this->em->flush();
        $io->success(\sprintf('Mot de passe de %s réinitialisé.', $this->emailAdministrateur));
        $this->afficherMotDePasse($io, $motDePasse);

        return Command::SUCCESS;
    }

    private function semerReferentielSiVierge(SymfonyStyle $io): void
    {
        if (0 < \count($this->em->getRepository(Permission::class)->findAll())) {
            $io->text('Référentiel des droits déjà en place : inchangé.');

            return;
        }

        $permissions = $this->referentiel->semerPermissions();
        $roles = $this->referentiel->semerRoles($permissions);
        $nbParametres = $this->referentiel->semerParametres();
        // Le compte d'administration est recherché plus loin par requête :
        // le référentiel doit être en base, pas seulement en mémoire.
        $this->em->flush();

        $io->definitionList(
            ['Permissions' => (string) \count($permissions)],
            ['Rôles' => (string) \count($roles)],
            ['Paramètres' => (string) $nbParametres],
        );
    }

    /**
     * Affiché une fois, à l'écran, jamais écrit sur disque : c'est à
     * l'exploitant de le transmettre et de ne pas le conserver.
     */
    private function afficherMotDePasse(SymfonyStyle $io, string $motDePasse): void
    {
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

    private function roleAdministrateur(): Role
    {
        return $this->em->getRepository(Role::class)->findOneBy(['code' => 'administrateur'])
            ?? throw new \RuntimeException('Rôle « administrateur » absent du référentiel.');
    }
}
