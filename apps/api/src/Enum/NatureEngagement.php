<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'un engagement — nomenclature des RSE (retours de tests,
 * octobre 2026) : « prestation intellectuelle » devient « service de
 * consultation », et le fonctionnement s'éclate en cinq natures pour que
 * salaires, missions et ateliers se suivent séparément.
 */
enum NatureEngagement: string
{
    case TRAVAUX = 'travaux';
    case FOURNITURES = 'fournitures';
    case ACQUISITION_BIENS = 'acquisition_biens';
    case SERVICE_CONSULTATION = 'service_consultation';
    case SERVICE_COURANT = 'service_courant';
    case SALAIRE = 'salaire';
    case FRAIS_MISSION = 'frais_mission';
    case ATELIER = 'atelier';
    case REMBOURSEMENT_TRANSPORT = 'remboursement_transport';
    case AUTRE_FONCTIONNEMENT = 'autre_fonctionnement';
}
