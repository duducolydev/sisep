<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Vérifie la configuration SMTP sans passer par un écran de l'application :
 * c'est le moyen le plus court de distinguer « le transport est mal
 * configuré » de « le code métier n'appelle pas l'envoi ». Contrairement à
 * ServiceCourriel, l'exception de transport n'est pas avalée — ici on veut
 * précisément voir le message d'erreur du serveur.
 */
#[AsCommand(name: 'app:courriel:test', description: 'Envoie un courriel de test pour vérifier la configuration SMTP')]
final class TesterCourrielCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $dsn,
        #[Autowire('%env(MAILER_EXPEDITEUR_ADRESSE)%')] private readonly string $expediteur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('destinataire', InputArgument::REQUIRED, 'Adresse à laquelle envoyer le message de test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $destinataire */
        $destinataire = $input->getArgument('destinataire');

        // Le DSN contient le mot de passe : n'afficher que le schéma et l'hôte.
        $dsnMasque = preg_replace('#://[^@]*@#', '://***@', $this->dsn) ?? '(illisible)';
        $io->definitionList(
            ['Transport' => $dsnMasque],
            ['Expéditeur' => $this->expediteur],
            ['Destinataire' => $destinataire],
        );

        if (str_starts_with($this->dsn, 'null://')) {
            $io->warning('MAILER_DSN vaut null://null : aucun message ne partira réellement. Renseignez .env.local.');
        }

        $courriel = (new Email())
            ->to($destinataire)
            ->subject('Test de configuration SMTP — SISEP')
            ->text("Ce message confirme que SISEP parvient à envoyer des courriels.\n")
            ->html('<p>Ce message confirme que <strong>SISEP</strong> parvient à envoyer des courriels.</p>');

        try {
            $this->mailer->send($courriel);
        } catch (TransportExceptionInterface $e) {
            $io->error(["L'envoi a échoué :", $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success('Message remis au transport sans erreur.');

        return Command::SUCCESS;
    }
}
