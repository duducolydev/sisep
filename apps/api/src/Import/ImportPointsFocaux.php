<?php

declare(strict_types=1);

namespace App\Import;

use App\Classeur\Normalisation;
use App\Entity\PointFocal;
use App\Repository\PointFocalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Import des points focaux des structures. Un point focal est identifié par
 * sa structure et son nom complet. La portée s'applique ligne par ligne,
 * comme à la saisie : un point focal de structure n'importe que pour la
 * sienne, les autres lignes sont refusées.
 */
final class ImportPointsFocaux implements DefinitionImport
{
    public function __construct(
        private readonly PointFocalRepository $pointsFocaux,
        private readonly ReferentielsImport $referentiels,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function type(): string
    {
        return 'points-focaux';
    }

    public function titre(): string
    {
        return 'Points focaux';
    }

    public function permission(): string
    {
        return 'points_focaux.gerer';
    }

    public function colonnes(): array
    {
        return [
            new ColonneImport('structure', 'Structure (sigle)', true, 'Sigle de la structure, tel que dans SISEP.', 'DSDS', ['Structure'], 'structures'),
            new ColonneImport('nomComplet', 'Prénom et nom', true, 'Prénom et nom du point focal.', 'Moussa Ndiaye', ['Nom complet', 'Nom et prénom', 'Prénom et nom'], largeur: 26),
            new ColonneImport('fonction', 'Fonction', true, 'Fonction dans la structure.', 'Chef de division', ['Poste'], largeur: 26),
            new ColonneImport('telephone', 'Téléphone', false, 'Numéro de téléphone.', '77 123 45 67', ['Telephone', 'Tel', 'Contact']),
            new ColonneImport('email', 'E-mail', false, 'Adresse électronique — reçoit les relances.', 'moussa.ndiaye@ansd.sn', ['Email', 'Courriel', 'Adresse e-mail'], largeur: 28),
            new ColonneImport('principal', 'Principal (Oui/Non)', false, 'Oui pour le point focal principal de la structure.', 'Oui', ['Principal'], 'ouiNon', largeur: 12),
            new ColonneImport('dateDesignation', 'Date de désignation', false, 'JJ/MM/AAAA.', '15/01/2026', ['Désignation', 'Date designation'], date: true),
        ];
    }

    public function listes(): array
    {
        return ['structures' => $this->referentiels->sigles(), 'ouiNon' => ['Oui', 'Non']];
    }

    public function analyser(array $lignes, bool $miseAJour): array
    {
        $existants = [];
        foreach ($this->pointsFocaux->findAll() as $pointFocal) {
            $existants[$this->cle((int) $pointFocal->getStructure()->getId(), $pointFocal->getNomComplet())] = $pointFocal;
        }

        $vues = [];
        $verdicts = [];
        foreach ($lignes as $numero => $v) {
            $erreurs = [];
            $nomComplet = LecteurFichierImport::texte($v['nomComplet'] ?? null);
            $fonction = LecteurFichierImport::texte($v['fonction'] ?? null);
            $sigle = LecteurFichierImport::texte($v['structure'] ?? null);
            $libelle = trim(($nomComplet ?? \sprintf('Ligne %d', $numero)).(null !== $sigle ? ' ('.$sigle.')' : ''));

            if (null === $sigle) {
                $erreurs[] = 'La structure est requise.';
            }
            if (null === $nomComplet) {
                $erreurs[] = 'Le prénom et le nom sont requis.';
            }
            if (null === $fonction) {
                $erreurs[] = 'La fonction est requise.';
            }
            $structure = $this->referentiels->structure($sigle, $erreurs);
            if (null !== $structure && !$this->security->isGranted('points_focaux.gerer', $structure)) {
                $erreurs[] = \sprintf('La structure %s est hors de votre périmètre.', $structure->getSigle());
            }
            $email = ReferentielsImport::courriel(LecteurFichierImport::texte($v['email'] ?? null), $erreurs);
            $principal = ReferentielsImport::ouiNon(LecteurFichierImport::texte($v['principal'] ?? null), 'Principal', $erreurs);
            $designation = ReferentielsImport::date($v['dateDesignation'] ?? null, 'Date de désignation', $erreurs);

            if ([] !== $erreurs || null === $structure || null === $nomComplet || null === $fonction) {
                $verdicts[] = LigneImport::erreur($numero, $libelle, $erreurs);

                continue;
            }

            $cle = $this->cle((int) $structure->getId(), $nomComplet);
            if (isset($vues[$cle])) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::DOUBLON, [\sprintf('Même point focal qu\'à la ligne %d : ligne ignorée.', $vues[$cle])]);

                continue;
            }
            $vues[$cle] = $numero;

            $existant = $existants[$cle] ?? null;
            if (null !== $existant && !$miseAJour) {
                $verdicts[] = new LigneImport($numero, $libelle, LigneImport::EXISTANTE, ['Point focal déjà enregistré : ligne ignorée.']);

                continue;
            }

            $telephone = LecteurFichierImport::texte($v['telephone'] ?? null);
            $application = function () use ($existant, $structure, $nomComplet, $fonction, $telephone, $email, $principal, $designation): void {
                $pointFocal = $existant ?? new PointFocal($structure, $nomComplet, $fonction);
                if (null === $existant) {
                    $this->em->persist($pointFocal);
                } else {
                    $pointFocal->setFonction($fonction);
                }
                null !== $telephone && $pointFocal->setTelephone($telephone);
                null !== $email && $pointFocal->setEmail($email);
                null !== $principal && $pointFocal->setPrincipal($principal);
                null !== $designation && $pointFocal->setDateDesignation($designation);
            };

            $verdicts[] = new LigneImport(
                $numero,
                $libelle,
                null === $existant ? LigneImport::CREATION : LigneImport::MISE_A_JOUR,
                null === $existant ? [] : ['Point focal déjà enregistré : sa fiche sera mise à jour.'],
                $application,
            );
        }

        return $verdicts;
    }

    private function cle(int $structureId, string $nomComplet): string
    {
        return $structureId.'|'.Normalisation::cle($nomComplet);
    }
}
