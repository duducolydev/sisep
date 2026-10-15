<?php

declare(strict_types=1);

namespace App\Import;

use App\Classeur\Normalisation;
use App\Entity\Contrat;
use App\Entity\Personnel;
use App\Repository\PersonnelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import du personnel technique. Un agent est identifié par son prénom et
 * son nom, sans tenir compte des accents ni de la casse : « Aïssatou DIOP »
 * et « aissatou diop » sont la même personne. Mêmes règles que la fiche :
 * nom, prénom, sexe et fonction requis ; la période de contrat ouvre le
 * premier contrat, ou corrige le contrat en cours lors d'une mise à jour.
 */
final class ImportPersonnel implements DefinitionImport
{
    public function __construct(
        private readonly PersonnelRepository $personnels,
        private readonly ReferentielsImport $referentiels,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'personnel';
    }

    public function titre(): string
    {
        return 'Personnel technique';
    }

    public function permission(): string
    {
        return 'rh.gerer';
    }

    public function colonnes(): array
    {
        return [
            new ColonneImport('prenom', 'Prénom', true, "Prénom(s) de l'agent.", 'Aïssatou', ['Prenom', 'Prénoms', 'Prenoms']),
            new ColonneImport('nom', 'Nom', true, "Nom de famille de l'agent.", 'Diop'),
            new ColonneImport('sexe', 'Sexe (F/M)', true, 'F pour féminin, M pour masculin.', 'F', ['Sexe', 'Genre'], 'sexes', largeur: 11),
            new ColonneImport('fonction', 'Fonction', true, 'Poste occupé sur le projet.', 'Chargée de suivi-évaluation', ['Poste'], largeur: 30),
            new ColonneImport('profil', 'Profil', false, 'Corps ou profil (ISE, ITS, AS…).', 'ISE', ['Corps']),
            new ColonneImport('specialite', 'Spécialité', false, 'Domaine de spécialité.', 'Statistiques sociales', ['Specialite'], largeur: 24),
            new ColonneImport('structure', 'Structure (sigle)', false, 'Sigle de la structure de rattachement, tel que dans SISEP.', 'DSDS', ['Structure'], 'structures'),
            new ColonneImport('operation', 'Opération (code PTBA)', false, "Code de l'activité du PTBA à laquelle l'agent est rattaché.", '1.2.3', ['Opération', 'Operation', 'Activité', 'Code activité'], 'operations', largeur: 20),
            new ColonneImport('email', 'E-mail', false, 'Adresse électronique.', 'aissatou.diop@ansd.sn', ['Email', 'Courriel', 'Adresse e-mail'], largeur: 28),
            new ColonneImport('telephone', 'Téléphone', false, 'Numéro de téléphone.', '77 123 45 67', ['Telephone', 'Tel', 'Contact']),
            new ColonneImport('debut', 'Début du contrat', false, 'JJ/MM/AAAA. Ouvre le contrat de l\'agent.', '01/01/2026', ['Date de début', 'Debut contrat'], date: true),
            new ColonneImport('fin', 'Fin du contrat', false, 'JJ/MM/AAAA, postérieure au début.', '31/12/2026', ['Date de fin', 'Fin contrat'], date: true),
            new ColonneImport('typeContrat', 'Type de contrat', false, 'Prestation, CDD…', 'Contrat de prestation', ['Type contrat'], largeur: 22),
        ];
    }

    public function listes(): array
    {
        return [
            'sexes' => ['F', 'M'],
            'structures' => $this->referentiels->sigles(),
            'operations' => $this->referentiels->codesOperations(),
        ];
    }

    public function analyser(array $lignes, bool $miseAJour): array
    {
        $existants = [];
        foreach ($this->personnels->listerNonSupprimes() as $personnel) {
            $existants[$this->cle($personnel->getPrenom(), $personnel->getNom())] = $personnel;
        }

        $vues = [];
        $verdicts = [];
        foreach ($lignes as $numero => $v) {
            $erreurs = [];
            $prenom = LecteurFichierImport::texte($v['prenom'] ?? null);
            $nom = LecteurFichierImport::texte($v['nom'] ?? null);
            $fonction = LecteurFichierImport::texte($v['fonction'] ?? null);
            $libelle = trim(($prenom ?? '').' '.($nom ?? '')) ?: \sprintf('Ligne %d', $numero);

            foreach (['Le prénom est requis.' => $prenom, 'Le nom est requis.' => $nom, 'La fonction est requise.' => $fonction] as $message => $valeur) {
                if (null === $valeur) {
                    $erreurs[] = $message;
                }
            }
            $sexe = ReferentielsImport::sexe(LecteurFichierImport::texte($v['sexe'] ?? null), $erreurs);
            if (null === LecteurFichierImport::texte($v['sexe'] ?? null)) {
                $erreurs[] = 'Le sexe est requis (F ou M).';
            }
            $structure = $this->referentiels->structure(LecteurFichierImport::texte($v['structure'] ?? null), $erreurs);
            $activite = $this->referentiels->activite(LecteurFichierImport::texte($v['operation'] ?? null), $erreurs);
            $email = ReferentielsImport::courriel(LecteurFichierImport::texte($v['email'] ?? null), $erreurs);
            $debut = ReferentielsImport::date($v['debut'] ?? null, 'Début du contrat', $erreurs);
            $fin = ReferentielsImport::date($v['fin'] ?? null, 'Fin du contrat', $erreurs);
            if (null !== $debut && null !== $fin && $fin < $debut) {
                $erreurs[] = 'La fin du contrat précède son début.';
            }
            if (null === $debut && null !== $fin) {
                $erreurs[] = 'Une fin de contrat suppose un début.';
            }

            if ([] !== $erreurs || null === $prenom || null === $nom || null === $fonction || null === $sexe) {
                $verdicts[] = LigneImport::erreur($numero, $libelle, $erreurs);

                continue;
            }

            $cle = $this->cle($prenom, $nom);
            if (isset($vues[$cle])) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::DOUBLON, [\sprintf('Même agent qu\'à la ligne %d : ligne ignorée.', $vues[$cle])]);

                continue;
            }
            $vues[$cle] = $numero;

            $existant = $existants[$cle] ?? null;
            if (null !== $existant && !$miseAJour) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::EXISTANTE, ['Agent déjà enregistré : ligne ignorée.']);

                continue;
            }

            $profil = LecteurFichierImport::texte($v['profil'] ?? null);
            $specialite = LecteurFichierImport::texte($v['specialite'] ?? null);
            $telephone = LecteurFichierImport::texte($v['telephone'] ?? null);
            $typeContrat = LecteurFichierImport::texte($v['typeContrat'] ?? null);

            $application = function () use ($existant, $prenom, $nom, $sexe, $fonction, $profil, $specialite, $structure, $activite, $email, $telephone, $debut, $fin, $typeContrat): void {
                $personnel = $existant ?? new Personnel($nom, $prenom, $sexe, $fonction);
                if (null === $existant) {
                    $this->em->persist($personnel);
                } else {
                    $personnel->setSexe($sexe);
                    $personnel->setFonction($fonction);
                }
                // Une cellule vide ne vide pas la fiche : seules les valeurs
                // renseignées remplacent celles déjà connues.
                null !== $profil && $personnel->setProfil($profil);
                null !== $specialite && $personnel->setSpecialite($specialite);
                null !== $structure && $personnel->setStructure($structure);
                null !== $activite && $personnel->setActivite($activite);
                null !== $email && $personnel->setEmail($email);
                null !== $telephone && $personnel->setTelephone($telephone);

                if (null !== $debut) {
                    $contrat = $personnel->getContratCourant();
                    if (null === $contrat) {
                        $contrat = new Contrat($personnel, $debut);
                        $this->em->persist($contrat);
                    } else {
                        $contrat->setDateDebut($debut);
                    }
                    $contrat->setDateFin($fin);
                    null !== $typeContrat && $contrat->setType($typeContrat);
                }
            };

            $verdicts[] = new LigneImport(
                $numero,
                $libelle,
                null === $existant ? LigneImport::CREATION : LigneImport::MISE_A_JOUR,
                null === $existant ? [] : ['Agent déjà enregistré : sa fiche sera mise à jour.'],
                $application,
            );
        }

        return $verdicts;
    }

    private function cle(string $prenom, string $nom): string
    {
        return Normalisation::cle($prenom).'|'.Normalisation::cle($nom);
    }
}
