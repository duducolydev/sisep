import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'

/** Engagements validés de l'exercice pour une nature (travaux, fournitures…). */
export interface EngagementsNature {
  nature: string
  montant: number
  nombre: number
}

export interface Synthese {
  exercice: number
  notifie: number
  engage: number
  depense: number
  disponible: number | null
  tauxEngagement: number | null
  tauxExecution: number | null
  /** Le plus lourd d'abord. */
  engagementsParNature: EngagementsNature[]
}

export const syntheseQueryOptions = (projetUuid: string | undefined, exercice?: number) =>
  queryOptions({
    queryKey: ['synthese', projetUuid, exercice ?? null],
    queryFn: () => apiClient.get<Synthese>(`/projets/${projetUuid}/synthese${exercice ? `?exercice=${exercice}` : ''}`),
    enabled: projetUuid !== undefined,
  })

export type Vigilance = 'en_retard' | 'risque_eleve'

export interface Alerte {
  uuid: string
  code: string
  libelle: string
  structure: string
  statut: string
  vigilance: Vigilance
  tauxExecution: number | null
  tauxAttendu: number | null
  dateFinPrevue: string | null
}

export const alertesQueryOptions = (projetUuid: string | undefined, exercice?: number) =>
  queryOptions({
    queryKey: ['alertes', projetUuid, exercice ?? null],
    queryFn: () => apiClient.get<{ exercice: number; alertes: Alerte[] }>(`/projets/${projetUuid}/alertes${exercice ? `?exercice=${exercice}` : ''}`),
    enabled: projetUuid !== undefined,
  })

export interface EvenementRecent {
  /** Identifiant de l'entrée de journal — seule clé unique d'un événement. */
  id: string
  horodatage: string
  type: string
  action: 'creation' | 'modification'
  libelleAction: string
  code: string | null
  libelleCible: string | null
  utilisateur: string | null
}

export const activiteRecenteQueryOptions = (projetUuid: string | undefined) =>
  queryOptions({
    queryKey: ['activite-recente', projetUuid],
    queryFn: () => apiClient.get<{ evenements: EvenementRecent[] }>(`/projets/${projetUuid}/activite-recente`),
    enabled: projetUuid !== undefined,
  })

export interface ExerciceDisponible {
  annee: number
  statut: string
}

/** Peuple SelecteurExercice — § décision : l'année est câblée de bout en bout, contrairement au trimestre/mois/jour (filtrage client uniquement). */
export const exercicesQueryOptions = (projetUuid: string | undefined) =>
  queryOptions({
    queryKey: ['exercices', projetUuid],
    queryFn: () => apiClient.get<{ donnees: ExerciceDisponible[] }>(`/projets/${projetUuid}/exercices`),
    enabled: projetUuid !== undefined,
    staleTime: 5 * 60_000,
  })
