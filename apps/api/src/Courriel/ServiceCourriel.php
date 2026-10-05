<?php

declare(strict_types=1);

namespace App\Courriel;

use App\Entity\InvitationIndicateur;
use App\Entity\RapportPeriodique;
use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Courriels applicatifs (création de compte, relance de reporting,
 * invitation sur un indicateur, changement de mot de passe).
 *
 * Règle structurante : un échec d'envoi n'interrompt jamais l'écriture
 * métier qui l'a déclenché. Une coupure SMTP ne doit pas empêcher de créer
 * un compte ni de relancer une structure — l'erreur est journalisée et la
 * méthode renvoie false, à charge de l'appelant d'en informer l'IHM s'il le
 * souhaite. L'expéditeur n'est pas fixé ici : il vient de l'en-tête `From`
 * de config/packages/mailer.yaml (variables MAILER_EXPEDITEUR_*).
 */
final class ServiceCourriel
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(APP_URL_WEB)%')] private readonly string $urlWeb,
    ) {
    }

    public function compteCree(Utilisateur $utilisateur, string $motDePasseTemporaire): bool
    {
        $nom = $this->proteger($utilisateur->getNomComplet());
        $email = $this->proteger($utilisateur->getEmail());
        $motDePasse = $this->proteger($motDePasseTemporaire);
        $bouton = $this->bouton($this->urlWeb.'/connexion', 'Me connecter');

        $corps = <<<HTML
            <p>Bonjour {$nom},</p>
            <p>Un compte vient de vous être ouvert sur SISEP, la plateforme de suivi-évaluation du projet PHASAOC.</p>
            <table role="presentation" style="border-collapse:collapse;margin:16px 0">
              <tr><td style="padding:4px 16px 4px 0;color:#5b6472">Identifiant</td><td style="font-family:monospace">{$email}</td></tr>
              <tr><td style="padding:4px 16px 4px 0;color:#5b6472">Mot de passe temporaire</td><td style="font-family:monospace;font-weight:700">{$motDePasse}</td></tr>
            </table>
            <p>Ce mot de passe est à usage unique : l'application vous demandera d'en choisir un nouveau dès votre première connexion.</p>
            {$bouton}
            <p style="color:#5b6472;font-size:13px">Si vous n'attendiez pas cette ouverture de compte, signalez-le à la coordination du projet.</p>
            HTML;

        return $this->envoyer($utilisateur->getEmail(), 'Votre compte SISEP a été créé', 'Votre compte SISEP', $corps);
    }

    public function relanceRapport(RapportPeriodique $rapport, string $destinataire, \DateTimeImmutable $maintenant): bool
    {
        $structure = $rapport->getStructure();
        $periode = $rapport->getPeriode()->value.' '.$rapport->getExercice()->getAnnee();
        $echeance = $rapport->getDateEcheance()->format('d/m/Y');
        $joursDeRetard = (int) $rapport->getDateEcheance()->diff($maintenant)->days;
        $sigle = $this->proteger($structure->getSigle());
        $bouton = $this->bouton($this->urlWeb.'/parties-prenantes/'.$structure->getUuid()->toRfc4122(), 'Transmettre le rapport');

        $corps = <<<HTML
            <p>Bonjour,</p>
            <p>Le rapport périodique <strong>{$this->proteger($periode)}</strong> de la structure <strong>{$sigle}</strong>
            était attendu pour le <strong>{$this->proteger($echeance)}</strong>, soit {$joursDeRetard} jour(s) de retard.</p>
            <p>Merci de le transmettre depuis la fiche de votre structure dans SISEP.</p>
            {$bouton}
            HTML;

        $sujet = \sprintf('Relance — rapport %s attendu (%s)', $periode, $structure->getSigle());

        return $this->envoyer($destinataire, $sujet, 'Relance de reporting', $corps);
    }

    public function invitationIndicateur(InvitationIndicateur $invitation): bool
    {
        $indicateur = $invitation->getIndicateur();
        $invite = $invitation->getUtilisateur();
        $nom = $this->proteger($invite->getNomComplet());
        $parrain = $this->proteger($invitation->getInvitePar()->getNomComplet());
        $libelle = $this->proteger($indicateur->getCode().' — '.$indicateur->getLibelle());
        $bouton = $this->bouton($this->urlWeb.'/indicateurs/'.$indicateur->getUuid()->toRfc4122(), "Ouvrir l'indicateur");

        $corps = <<<HTML
            <p>Bonjour {$nom},</p>
            <p>{$parrain} vous a ouvert l'accès à l'indicateur <strong>{$libelle}</strong> dans SISEP.</p>
            <p>Vous pouvez désormais le consulter et y saisir ses valeurs périodiques. Cet accès ne porte que sur cet
            indicateur précis.</p>
            {$bouton}
            HTML;

        $sujet = \sprintf("Vous êtes invité à renseigner l'indicateur %s", $indicateur->getCode());

        return $this->envoyer($invite->getEmail(), $sujet, 'Invitation à saisir un indicateur', $corps);
    }

    public function motDePasseChange(Utilisateur $utilisateur, \DateTimeImmutable $maintenant): bool
    {
        $nom = $this->proteger($utilisateur->getNomComplet());
        $horodatage = $this->proteger($maintenant->format('d/m/Y à H:i').' (UTC)');

        $corps = <<<HTML
            <p>Bonjour {$nom},</p>
            <p>Le mot de passe de votre compte SISEP a été modifié le <strong>{$horodatage}</strong>.</p>
            <p style="color:#5b6472;font-size:13px">Si vous n'êtes pas à l'origine de ce changement, prévenez immédiatement
            la coordination du projet : votre compte est peut-être compromis.</p>
            HTML;

        return $this->envoyer($utilisateur->getEmail(), 'Votre mot de passe SISEP a été modifié', 'Mot de passe modifié', $corps);
    }

    private function envoyer(string $destinataire, string $sujet, string $titre, string $corpsHtml): bool
    {
        $html = $this->gabarit($titre, $corpsHtml);

        $courriel = (new Email())
            ->to($destinataire)
            ->subject($sujet)
            ->text($this->versTexte($html))
            ->html($html);

        try {
            $this->mailer->send($courriel);

            return true;
        } catch (TransportExceptionInterface $e) {
            // Volontairement avalé : l'écriture métier appelante est déjà
            // validée en base, la perdre pour une panne SMTP serait pire que
            // l'absence de notification.
            $this->logger->error("Échec d'envoi de courriel SISEP", [
                'destinataire' => $destinataire,
                'sujet' => $sujet,
                'erreur' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Habillage commun : en-tête SISEP, corps, pied. Styles en ligne uniquement — les feuilles de style externes ne sont pas appliquées par les clients de messagerie. */
    private function gabarit(string $titre, string $corpsHtml): string
    {
        $titreProtege = $this->proteger($titre);

        return <<<HTML
            <!doctype html>
            <html lang="fr">
            <head><meta charset="utf-8"></head>
            <body style="margin:0;background:#f4f4f1;font-family:Arial,Helvetica,sans-serif;color:#14181f">
              <table role="presentation" width="100%" style="border-collapse:collapse;padding:24px 0">
                <tr><td align="center">
                  <table role="presentation" width="560" style="border-collapse:collapse;max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden">
                    <tr><td style="background:#1b4f8a;padding:16px 24px;color:#ffffff">
                      <strong style="font-size:18px">SISEP</strong>
                      <span style="opacity:.75;font-size:12px;letter-spacing:.12em;text-transform:uppercase"> · PHASAOC · ANSD</span>
                    </td></tr>
                    <tr><td style="padding:24px">
                      <h1 style="margin:0 0 12px;font-size:19px">{$titreProtege}</h1>
                      {$corpsHtml}
                    </td></tr>
                    <tr><td style="padding:16px 24px;border-top:1px solid #e3e5e8;color:#5b6472;font-size:12px">
                      Message automatique de la plateforme de suivi-évaluation du PHASAOC — merci de ne pas y répondre.
                    </td></tr>
                  </table>
                </td></tr>
              </table>
            </body>
            </html>
            HTML;
    }

    private function bouton(string $url, string $libelle): string
    {
        $urlProtegee = $this->proteger($url);
        $libelleProtege = $this->proteger($libelle);

        return <<<HTML
            <p style="margin:20px 0"><a href="{$urlProtegee}" style="display:inline-block;background:#1b4f8a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:700">{$libelleProtege}</a></p>
            HTML;
    }

    /** Variante texte pour les clients qui refusent le HTML — dérivée du même contenu, jamais rédigée séparément (sinon les deux versions divergent). */
    private function versTexte(string $html): string
    {
        $texte = preg_replace('#</(p|h1|tr|table)>#', "\n", $html) ?? $html;
        $texte = str_replace(['<br>', '<br/>', '<br />'], "\n", $texte);
        $texte = html_entity_decode(strip_tags($texte), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $texte = preg_replace('/[ \t]+/', ' ', $texte) ?? $texte;
        $texte = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $texte) ?? $texte;

        return trim($texte);
    }

    private function proteger(string $valeur): string
    {
        return htmlspecialchars($valeur, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
