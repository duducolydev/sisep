<?php

declare(strict_types=1);

namespace App\Http;

use App\Budget\GrandeursDerivees;
use App\Entity\Initiative;
use App\Entity\InitiativeActivite;
use App\Entity\TacheInitiative;
use App\Enum\VisibilitePieceJointe;
use App\Repository\ExerciceRepository;
use App\Repository\InitiativeActiviteRepository;
use App\Repository\NoteInitiativeRepository;
use App\Repository\PieceJointeRepository;
use App\Repository\ProjetRepository;
use App\Repository\TacheInitiativeRepository;
use App\Vigilance\StatutInitiativeCalculator;
use Doctrine\DBAL\Connection;

final class InitiativeNormalizer
{
    public function __construct(
        private readonly InitiativeActiviteRepository $activites,
        private readonly TacheInitiativeRepository $taches,
        private readonly NoteInitiativeRepository $notes,
        private readonly PieceJointeRepository $piecesJointes,
        private readonly StatutInitiativeCalculator $statutCalculator,
        private readonly Connection $connection,
        private readonly ProjetRepository $projets,
        private readonly ExerciceRepository $exercices,
    ) {
    }

    /** @return array<string, mixed> */
    public function normaliser(Initiative $initiative): array
    {
        $activites = $this->activites->listerNonSupprimees($initiative);

        return [
            'uuid' => $initiative->getUuid()->toRfc4122(),
            'libelle' => $initiative->getLibelle(),
            'description' => $initiative->getDescription(),
            'lienPtba' => $this->normaliserLienPtba($initiative),
            'creePar' => [
                'uuid' => $initiative->getCreePar()->getUuid()->toRfc4122(),
                'nomComplet' => $initiative->getCreePar()->getNomComplet(),
            ],
            'activites' => array_map($this->normaliserActivite(...), $activites),
        ];
    }

    /** @return array<string, mixed>|null */
    private function normaliserLienPtba(Initiative $initiative): ?array
    {
        $composante = $initiative->getComposante();
        $sousComposante = $initiative->getSousComposante();
        if (null === $composante && null === $sousComposante) {
            return null;
        }

        $projet = $this->projets->findOneBy(['actif' => true]) ?? $this->projets->findOneBy([]);
        $exercice = null !== $projet ? $this->exercices->trouverParAnnee($projet, (int) date('Y')) : null;
        $tauxExecutionPtba = null;

        if (null !== $exercice) {
            if (null !== $sousComposante) {
                $ligne = $this->connection->fetchAssociative(
                    'SELECT notifie, depense FROM v_execution_sous_composante WHERE sous_composante_id = ? AND exercice_id = ?',
                    [$sousComposante->getId(), $exercice->getId()],
                );
            } else {
                // Garanti non nul : le retour anticipé plus haut exclut le cas où composante et sous-composante sont toutes deux nulles.
                $ligne = $this->connection->fetchAssociative(
                    'SELECT notifie, depense FROM v_execution_composante WHERE composante_id = ? AND exercice_id = ?',
                    [$composante->getId(), $exercice->getId()],
                );
            }

            if (false !== $ligne) {
                $notifie = null !== $ligne['notifie'] ? (int) $ligne['notifie'] : null;
                $depense = null !== $ligne['depense'] ? (int) $ligne['depense'] : null;
                $tauxExecutionPtba = GrandeursDerivees::taux($depense, $notifie);
            }
        }

        $cible = $sousComposante ?? $composante;

        return [
            'type' => null !== $sousComposante ? 'sous_composante' : 'composante',
            'uuid' => $cible->getUuid()->toRfc4122(),
            'code' => $cible->getCode(),
            'libelle' => $cible->getLibelle(),
            'tauxExecutionPtba' => $tauxExecutionPtba,
        ];
    }

    /** @return array<string, mixed> */
    private function normaliserActivite(InitiativeActivite $activite): array
    {
        $taches = $this->taches->listerNonSupprimees($activite);
        $total = \count($taches);
        $faites = \count(array_filter($taches, static fn ($t) => $t->estTerminee()));
        $avancement = $total > 0 ? (float) $faites / $total : null;

        $dateDebut = $activite->getDateDebut();
        $echeance = $activite->getEcheance();
        $statut = $this->statutCalculator->calculer($dateDebut, $echeance, $avancement);
        $dateReelle = 1.0 === $avancement
            ? max(array_map(static fn (TacheInitiative $t) => $t->getDateRealisation(), $taches))
            : null;

        return [
            'uuid' => $activite->getUuid()->toRfc4122(),
            'libelle' => $activite->getLibelle(),
            'dateDebut' => $dateDebut?->format('Y-m-d'),
            'echeance' => $echeance?->format('Y-m-d'),
            'statut' => $statut->value,
            'dateReelle' => $dateReelle?->format('Y-m-d'),
            'avancement' => $avancement,
            'responsables' => array_map(static fn ($u) => [
                'uuid' => $u->getUuid()->toRfc4122(),
                'nomComplet' => $u->getNomComplet(),
            ], $activite->getResponsables()->toArray()),
            'notes' => $this->normaliserNotes('initiative_activite', (int) $activite->getId()),
            'piecesJointes' => $this->normaliserPiecesJointes('initiative_activite', (int) $activite->getId()),
            'taches' => array_map(fn (TacheInitiative $t) => $this->normaliserTache($t), $taches),
        ];
    }

    /** @return array<string, mixed> */
    private function normaliserTache(TacheInitiative $tache): array
    {
        $activite = $tache->getInitiativeActivite();
        $statut = $this->statutCalculator->calculer($activite->getDateDebut(), $activite->getEcheance(), $tache->estTerminee() ? 1.0 : 0.0);

        return [
            'uuid' => $tache->getUuid()->toRfc4122(),
            'libelle' => $tache->getLibelle(),
            'ordre' => $tache->getOrdre(),
            'statut' => $statut->value,
            'responsable' => null !== $tache->getResponsable() ? [
                'uuid' => $tache->getResponsable()->getUuid()->toRfc4122(),
                'nomComplet' => $tache->getResponsable()->getNomComplet(),
            ] : null,
            'dateRealisation' => $tache->getDateRealisation()?->format(\DateTimeInterface::ATOM),
            'notes' => $this->normaliserNotes('tache_initiative', (int) $tache->getId()),
            'piecesJointes' => $this->normaliserPiecesJointes('tache_initiative', (int) $tache->getId()),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normaliserNotes(string $entiteType, int $entiteId): array
    {
        $notes = $this->notes->findBy(['entiteType' => $entiteType, 'entiteId' => $entiteId], ['creeLe' => 'ASC']);

        return array_map(static fn ($n) => [
            'uuid' => $n->getUuid()->toRfc4122(),
            'contenu' => $n->getContenu(),
            'creeLe' => $n->getCreeLe()->format(\DateTimeInterface::ATOM),
            'creePar' => $n->getCreePar()?->getNomComplet(),
        ], $notes);
    }

    /** @return list<array<string, mixed>> */
    private function normaliserPiecesJointes(string $entiteType, int $entiteId): array
    {
        $pieces = $this->piecesJointes->findBy(['entiteType' => $entiteType, 'entiteId' => $entiteId], ['creeLe' => 'ASC']);

        return array_map(fn ($p) => [
            'uuid' => $p->getUuid()->toRfc4122(),
            'libelle' => $p->getLibelle(),
            'nomFichier' => $p->getNomFichier(),
            'typeDocument' => $p->getTypeDocument()?->value,
            'typeDocumentPrecision' => $p->getTypeDocumentPrecision(),
            'typeDocumentLibelle' => $p->libelleTypeDocument(),
            // Le déposant peut requalifier sa pièce (PATCH /pieces-jointes/{uuid}).
            'deposeParUuid' => $p->getCreePar()->getUuid()->toRfc4122(),
            'mime' => $p->getMime(),
            'tailleOctets' => $p->getTailleOctets(),
            'creeLe' => $p->getCreeLe()->format(\DateTimeInterface::ATOM),
            'visibilite' => $p->getVisibilite()->value,
            'agentsVisibilite' => VisibilitePieceJointe::AGENTS_SPECIFIQUES === $p->getVisibilite()
                ? array_map(static fn ($a) => ['uuid' => $a->getUuid()->toRfc4122(), 'nomComplet' => $a->getNomComplet()], $p->getAgentsVisibilite()->toArray())
                : [],
        ], $pieces);
    }
}
