<?php

declare(strict_types=1);

namespace App\Import;

use App\Classeur\Normalisation;
use App\Entity\Depense;
use App\Entity\Engagement;
use App\Entity\Utilisateur;
use App\Enum\StatutEcriture;
use App\Repository\DepenseRepository;
use App\Repository\EngagementRepository;
use App\ValueObject\Montant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Import des dépenses (paiements), rattachées à leur engagement par la
 * référence de celui-ci — d'où l'ordre : engagements d'abord, dépenses
 * ensuite.
 *
 * Comme les engagements, les dépenses arrivent en brouillon : leur
 * validation contrôle, sous verrou, que le décaissé ne dépasse pas
 * l'engagé, et qu'elle n'est pas faite par la personne qui a importé.
 */
final class ImportDepenses implements DefinitionImport
{
    public function __construct(
        private readonly EngagementRepository $engagements,
        private readonly DepenseRepository $depenses,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function type(): string
    {
        return 'depenses';
    }

    public function titre(): string
    {
        return 'Dépenses';
    }

    public function permission(): string
    {
        return 'budget.depense.saisir';
    }

    public function colonnes(): array
    {
        return [
            new ColonneImport('engagement', 'Référence de l\'engagement', true, 'Référence de l\'engagement payé, telle que saisie ou importée dans SISEP.', 'BC-2026-014', ['Engagement', 'N° engagement', 'Référence engagement', 'Référence marché'], largeur: 22),
            new ColonneImport('montant', 'Montant (FCFA)', true, 'Montant payé en FCFA, sans décimales.', '6250000', ['Montant', 'Montant payé', 'Montant décaissé'], largeur: 16),
            new ColonneImport('date', 'Date de paiement', true, 'JJ/MM/AAAA.', '30/04/2026', ['Date', 'Date paiement', 'Date de la dépense'], date: true, largeur: 14),
            new ColonneImport('referencePaiement', 'Référence du paiement', false, 'N° d\'ordre de paiement, de chèque ou de virement. Recommandée : elle évite les doublons.', 'OP-2026-087', ['Référence paiement', 'N° paiement', 'N° OP'], largeur: 20),
            new ColonneImport('exercice', 'Exercice de l\'engagement', false, 'À préciser si la même référence d\'engagement existe sur plusieurs exercices.', '2026', ['Exercice', 'Année'], largeur: 12),
        ];
    }

    public function listes(): array
    {
        return [];
    }

    public function analyser(array $lignes, bool $miseAJour): array
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }

        /** @var array<string, list<Engagement>> $parReference */
        $parReference = [];
        foreach ($this->engagements->findBy(['supprimeLe' => null]) as $engagement) {
            if (null !== $engagement->getReferenceMarche()) {
                $parReference[Normalisation::cle($engagement->getReferenceMarche())][] = $engagement;
            }
        }

        /** @var array<string, true> $existantes */
        $existantes = [];
        foreach ($this->depenses->findBy(['supprimeLe' => null]) as $depense) {
            if (StatutEcriture::ANNULE !== $depense->getStatut()) {
                $existantes[$this->cle($depense->getEngagement(), $depense->getReferencePaiement(), $depense->getDateDepense(), $depense->getMontant()->versFcfa())] = true;
            }
        }

        $vues = [];
        /** @var array<int, int> $cumuls montant des lignes du fichier, par engagement */
        $cumuls = [];
        $verdicts = [];
        foreach ($lignes as $numero => $v) {
            $erreurs = [];
            $referenceEngagement = LecteurFichierImport::texte($v['engagement'] ?? null);
            $montant = ReferentielsImport::montant($v['montant'] ?? null, 'Montant', $erreurs);
            $referencePaiement = LecteurFichierImport::texte($v['referencePaiement'] ?? null);
            $libelle = trim(($referencePaiement ?? \sprintf('Ligne %d', $numero)).' → '.($referenceEngagement ?? '?').(null !== $montant ? ' — '.Montant::fromFcfa($montant)->formater() : ''));

            if (null === $referenceEngagement) {
                $erreurs[] = 'La référence de l\'engagement est requise.';
            }
            if (null === $v['montant'] || '' === $v['montant']) {
                $erreurs[] = 'Le montant est requis.';
            }
            if (null !== $referencePaiement && mb_strlen($referencePaiement) > 80) {
                $erreurs[] = 'La référence du paiement tient en 80 caractères au plus.';
            }
            $date = ReferentielsImport::date($v['date'] ?? null, 'Date de paiement', $erreurs);
            if (null === $date && [] === array_filter($erreurs, static fn (string $e) => str_starts_with($e, 'Date'))) {
                $erreurs[] = 'La date de paiement est requise.';
            }
            $annee = ReferentielsImport::entier($v['exercice'] ?? null, 'Exercice', $erreurs, 2000, 2100);

            $engagement = null;
            if (null !== $referenceEngagement) {
                $engagement = $this->engagement($parReference[Normalisation::cle($referenceEngagement)] ?? [], $referenceEngagement, $annee, $date, $erreurs);
            }
            if (null !== $engagement) {
                if (StatutEcriture::ANNULE === $engagement->getStatut()) {
                    $erreurs[] = \sprintf('L\'engagement %s est annulé : aucune dépense ne s\'y rattache.', $referenceEngagement);
                } elseif (!$engagement->getExercice()->estOuvert()) {
                    $erreurs[] = \sprintf('L\'exercice %d est clôturé : aucune écriture budgétaire n\'est plus acceptée.', $engagement->getExercice()->getAnnee());
                } elseif (!$this->security->isGranted('budget.depense.saisir', $engagement->getCible())) {
                    $erreurs[] = 'Cet engagement est hors de votre périmètre de saisie.';
                }
            }

            if ([] !== $erreurs || null === $engagement || null === $montant || null === $date) {
                $verdicts[] = LigneImport::erreur($numero, $libelle, $erreurs);

                continue;
            }

            $cle = $this->cle($engagement, $referencePaiement, $date, $montant);
            if (isset($vues[$cle])) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::DOUBLON, [\sprintf('Même paiement qu\'à la ligne %d : ligne ignorée.', $vues[$cle])]);

                continue;
            }
            $vues[$cle] = $numero;
            if (isset($existantes[$cle])) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::EXISTANTE, ['Dépense déjà enregistrée sur cet engagement : ligne ignorée.']);

                continue;
            }

            $messages = ['Créée en brouillon : à valider par une autre personne.'];
            if (StatutEcriture::BROUILLON === $engagement->getStatut()) {
                $messages[] = 'L\'engagement n\'est pas encore validé : cette dépense ne pourra être validée qu\'après lui.';
            }
            $idEngagement = (int) $engagement->getId();
            $cumuls[$idEngagement] = ($cumuls[$idEngagement] ?? 0) + $montant;
            $total = $this->depenses->sommeValidee($engagement)->ajouter(Montant::fromFcfa($cumuls[$idEngagement]));
            if ($total->estSuperieurA($engagement->getMontant())) {
                $messages[] = \sprintf(
                    'Attention : avec les dépenses déjà validées et celles du fichier, l\'engagement atteindrait %s décaissés pour %s engagés — la validation sera refusée au-delà.',
                    $total->formater(),
                    $engagement->getMontant()->formater(),
                );
            }

            $application = function () use ($engagement, $montant, $date, $referencePaiement, $utilisateur): void {
                $depense = new Depense($engagement, Montant::fromFcfa($montant), $date, $utilisateur);
                $depense->setReferencePaiement($referencePaiement);
                $this->em->persist($depense);
            };
            $verdicts[] = new LigneImport($numero, $libelle, LigneImport::CREATION, $messages, $application);
        }

        return $verdicts;
    }

    /**
     * L'engagement désigné par sa référence ; s'il y en a plusieurs (une
     * même référence reprise d'un exercice à l'autre), l'exercice indiqué ou,
     * à défaut, celui de la date de paiement tranche.
     *
     * @param list<Engagement> $candidats
     * @param list<string>     $erreurs
     */
    private function engagement(array $candidats, string $reference, ?int $annee, ?\DateTimeImmutable $date, array &$erreurs): ?Engagement
    {
        if ([] === $candidats) {
            $erreurs[] = \sprintf('Aucun engagement de référence « %s » dans SISEP : importez ou saisissez d\'abord l\'engagement.', $reference);

            return null;
        }
        $annee ??= \count($candidats) > 1 && null !== $date ? (int) $date->format('Y') : null;
        if (null !== $annee) {
            $candidats = array_values(array_filter($candidats, static fn (Engagement $e) => $e->getExercice()->getAnnee() === $annee));
        }
        if (1 !== \count($candidats)) {
            $erreurs[] = [] === $candidats
                ? \sprintf('Aucun engagement de référence « %s » sur l\'exercice %d.', $reference, (int) $annee)
                : \sprintf('Plusieurs engagements portent la référence « %s » : précisez l\'exercice.', $reference);

            return null;
        }

        return $candidats[0];
    }

    /** Un paiement : sa référence s'il en a une, sinon sa date et son montant, sur un engagement donné. */
    private function cle(Engagement $engagement, ?string $referencePaiement, \DateTimeImmutable $date, int $montant): string
    {
        return $engagement->getId().'|'.(null !== $referencePaiement && '' !== $referencePaiement
            ? 'R'.Normalisation::cle($referencePaiement)
            : 'D'.$date->format('Y-m-d').'|'.$montant);
    }
}
