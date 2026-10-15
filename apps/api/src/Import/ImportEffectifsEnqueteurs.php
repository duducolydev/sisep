<?php

declare(strict_types=1);

namespace App\Import;

use App\Classeur\Normalisation;
use App\Entity\EffectifEnqueteurs;
use App\Repository\EffectifEnqueteursRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import des effectifs d'enquêteurs. Une ligne = une opération, une année,
 * un libellé de collecte (facultatif) : c'est la clé de doublon, en base
 * comme dans le fichier. Mêmes règles que la saisie : opération et année
 * requises, effectifs entiers, fin de mobilisation postérieure au début.
 */
final class ImportEffectifsEnqueteurs implements DefinitionImport
{
    public function __construct(
        private readonly EffectifEnqueteursRepository $effectifs,
        private readonly ReferentielsImport $referentiels,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'effectifs-enqueteurs';
    }

    public function titre(): string
    {
        return 'Effectifs d\'enquêteurs';
    }

    public function permission(): string
    {
        return 'rh.gerer';
    }

    public function colonnes(): array
    {
        return [
            new ColonneImport('operation', 'Opération (code PTBA)', true, "Code de l'activité du PTBA (opération de collecte).", '1.2.2', ['Opération', 'Operation', 'Activité', 'Code activité'], 'operations', largeur: 20),
            new ColonneImport('annee', 'Année', true, 'Année de la collecte.', '2026', ['Annee', 'Exercice'], largeur: 10),
            new ColonneImport('libelle', 'Libellé de la collecte', false, 'Précise la vague ou la phase si une opération en compte plusieurs.', 'Phase pilote', ['Libellé', 'Libelle', 'Collecte'], largeur: 26),
            new ColonneImport('femmes', 'Nombre de femmes', true, 'Enquêtrices mobilisées.', '12', ['Femmes'], largeur: 14),
            new ColonneImport('hommes', 'Nombre d\'hommes', true, 'Enquêteurs mobilisés.', '18', ['Hommes'], largeur: 14),
            new ColonneImport('debut', 'Début de mobilisation', false, 'JJ/MM/AAAA.', '01/03/2026', ['Début', 'Debut', 'Date de début'], date: true),
            new ColonneImport('fin', 'Fin de mobilisation', false, 'JJ/MM/AAAA, postérieure au début.', '30/04/2026', ['Fin', 'Date de fin'], date: true),
        ];
    }

    public function listes(): array
    {
        return ['operations' => $this->referentiels->codesOperations()];
    }

    public function analyser(array $lignes, bool $miseAJour): array
    {
        $existants = [];
        foreach ($this->effectifs->listerNonSupprimes() as $effectif) {
            $existants[$this->cle((int) $effectif->getActivite()->getId(), $effectif->getAnnee(), $effectif->getLibelle())] = $effectif;
        }

        $vues = [];
        $verdicts = [];
        foreach ($lignes as $numero => $v) {
            $erreurs = [];
            $code = LecteurFichierImport::texte($v['operation'] ?? null);
            $libelleCollecte = LecteurFichierImport::texte($v['libelle'] ?? null);
            $annee = ReferentielsImport::entier($v['annee'] ?? null, 'Année', $erreurs, 2000, 2100);
            $femmes = ReferentielsImport::entier($v['femmes'] ?? null, 'Nombre de femmes', $erreurs);
            $hommes = ReferentielsImport::entier($v['hommes'] ?? null, 'Nombre d\'hommes', $erreurs);
            $libelle = trim(($code ?? '?').' — '.($annee ?? '?').(null !== $libelleCollecte ? ' — '.$libelleCollecte : ''));

            if (null === $code) {
                $erreurs[] = "L'opération est requise.";
            }
            $activite = $this->referentiels->activite($code, $erreurs);
            if (null === LecteurFichierImport::texte($v['annee'] ?? null)) {
                $erreurs[] = "L'année est requise.";
            }
            if (null === LecteurFichierImport::texte($v['femmes'] ?? null) || null === LecteurFichierImport::texte($v['hommes'] ?? null)) {
                $erreurs[] = 'Les nombres de femmes et d\'hommes sont requis (0 si aucun).';
            }
            $debut = ReferentielsImport::date($v['debut'] ?? null, 'Début de mobilisation', $erreurs);
            $fin = ReferentielsImport::date($v['fin'] ?? null, 'Fin de mobilisation', $erreurs);
            if (null !== $debut && null !== $fin && $fin < $debut) {
                $erreurs[] = 'La fin de mobilisation précède son début.';
            }

            if ([] !== $erreurs || null === $activite || null === $annee || null === $femmes || null === $hommes) {
                $verdicts[] = LigneImport::erreur($numero, $libelle, $erreurs);

                continue;
            }

            $cle = $this->cle((int) $activite->getId(), $annee, $libelleCollecte);
            if (isset($vues[$cle])) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::DOUBLON, [\sprintf('Même collecte qu\'à la ligne %d : ligne ignorée.', $vues[$cle])]);

                continue;
            }
            $vues[$cle] = $numero;

            $existant = $existants[$cle] ?? null;
            if (null !== $existant && !$miseAJour) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::EXISTANTE, ['Effectif déjà enregistré pour cette collecte : ligne ignorée.']);

                continue;
            }

            $application = function () use ($existant, $activite, $annee, $libelleCollecte, $femmes, $hommes, $debut, $fin): void {
                $effectif = $existant ?? new EffectifEnqueteurs($activite, $annee);
                if (null === $existant) {
                    $effectif->setLibelle($libelleCollecte);
                    $this->em->persist($effectif);
                }
                $effectif->definirEffectifs($femmes, $hommes);
                if (null !== $debut || null !== $fin) {
                    $effectif->definirPeriode($debut ?? $effectif->getDateDebut(), $fin ?? $effectif->getDateFin());
                }
            };

            $verdicts[] = new LigneImport(
                $numero,
                $libelle,
                null === $existant ? LigneImport::CREATION : LigneImport::MISE_A_JOUR,
                null === $existant ? [] : ['Collecte déjà enregistrée : ses effectifs seront mis à jour.'],
                $application,
            );
        }

        return $verdicts;
    }

    private function cle(int $activiteId, int $annee, ?string $libelle): string
    {
        return $activiteId.'|'.$annee.'|'.Normalisation::cle($libelle ?? '');
    }
}
