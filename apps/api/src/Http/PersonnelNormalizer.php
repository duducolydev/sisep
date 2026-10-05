<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Contrat;
use App\Entity\EffectifEnqueteurs;
use App\Entity\Personnel;

/** N'expose aucune donnée salariale : elles ne sont pas stockées (§ 14 du CR du 22/09/2026). */
final class PersonnelNormalizer
{
    /** @return array<string, mixed> */
    public function normaliser(Personnel $personnel, \DateTimeImmutable $maintenant): array
    {
        $courant = $personnel->getContratCourant();

        return [
            'uuid' => $personnel->getUuid()->toRfc4122(),
            'nom' => $personnel->getNom(),
            'prenom' => $personnel->getPrenom(),
            'nomComplet' => $personnel->getNomComplet(),
            'sexe' => $personnel->getSexe()->value,
            'fonction' => $personnel->getFonction(),
            'profil' => $personnel->getProfil(),
            'specialite' => $personnel->getSpecialite(),
            'email' => $personnel->getEmail(),
            'telephone' => $personnel->getTelephone(),
            'structure' => null === $personnel->getStructure() ? null : [
                'uuid' => $personnel->getStructure()->getUuid()->toRfc4122(),
                'sigle' => $personnel->getStructure()->getSigle(),
            ],
            'activite' => null === $personnel->getActivite() ? null : [
                'uuid' => $personnel->getActivite()->getUuid()->toRfc4122(),
                'code' => $personnel->getActivite()->getCode(),
                'libelle' => $personnel->getActivite()->getLibelle(),
            ],
            // Le contrat courant est remonté à part : c'est lui qui porte
            // l'échéance surveillée, les autres ne sont que l'historique.
            'contratCourant' => null === $courant ? null : $this->normaliserContrat($courant, $maintenant),
            'contrats' => array_map(
                fn (Contrat $c) => $this->normaliserContrat($c, $maintenant),
                array_values($personnel->getContratsActifs()->toArray()),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function normaliserContrat(Contrat $contrat, \DateTimeImmutable $maintenant): array
    {
        return [
            'uuid' => $contrat->getUuid()->toRfc4122(),
            'dateDebut' => $contrat->getDateDebut()->format('Y-m-d'),
            'dateFin' => $contrat->getDateFin()?->format('Y-m-d'),
            'type' => $contrat->getType(),
            'observation' => $contrat->getObservation(),
            'dureeEnJours' => $contrat->dureeEnJours(),
            'joursAvantEcheance' => $contrat->joursAvantEcheance($maintenant),
        ];
    }

    /** @return array<string, mixed> */
    public function normaliserEffectif(EffectifEnqueteurs $effectif): array
    {
        return [
            'uuid' => $effectif->getUuid()->toRfc4122(),
            'activite' => [
                'uuid' => $effectif->getActivite()->getUuid()->toRfc4122(),
                'code' => $effectif->getActivite()->getCode(),
                'libelle' => $effectif->getActivite()->getLibelle(),
            ],
            'libelle' => $effectif->getLibelle(),
            'annee' => $effectif->getAnnee(),
            'nombreFemmes' => $effectif->getNombreFemmes(),
            'nombreHommes' => $effectif->getNombreHommes(),
            'effectifTotal' => $effectif->effectifTotal(),
            'partFemmes' => $effectif->partFemmes(),
        ];
    }
}
