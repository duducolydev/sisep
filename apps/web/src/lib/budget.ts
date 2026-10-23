import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'

export interface LigneExecution {
  uuid: string
  code: string
  libelle: string
  notifie: number | null
  engage: number | null
  depense: number | null
  disponible: number | null
  tauxEngagement: number | null
  tauxExecution: number | null
  tauxConsommation: number | null
}

export interface ReponseBudgetExecution {
  exercice: number
  niveau: 'composante' | 'sous_composante' | 'activite'
  donnees: LigneExecution[]
}

export type NiveauBudget = ReponseBudgetExecution['niveau']

export const budgetExecutionQueryOptions = (niveau: NiveauBudget, composanteUuid?: string) =>
  queryOptions({
    queryKey: ['budget', 'execution', niveau, composanteUuid ?? null],
    queryFn: () =>
      apiClient.get<ReponseBudgetExecution>(
        `/budget/execution?niveau=${niveau}${composanteUuid ? `&composante=${composanteUuid}` : ''}`,
      ),
  })

export interface PointDecaissement {
  mois: string
  realise: number
  prevu: number
}

export const decaissementQueryOptions = (projetUuid: string | undefined, exercice?: number) =>
  queryOptions({
    queryKey: ['decaissement', projetUuid, exercice ?? null],
    queryFn: () =>
      apiClient.get<{ exercice: number; points: PointDecaissement[] }>(
        `/projets/${projetUuid}/decaissement${exercice ? `?exercice=${exercice}` : ''}`,
      ),
    enabled: projetUuid !== undefined,
  })

export interface ExecutionAnnee {
  annee: number
  notifie: number
  depense: number
  disponible: number
  tauxExecution: number | null
}

export interface ExecutionGlobale {
  notifie: number
  depense: number
  disponible: number
  tauxExecution: number | null
}

export interface ReponseExecutionPluriannuelle {
  annees: ExecutionAnnee[]
  global: ExecutionGlobale
}

/** Tout le projet, tous exercices confondus — contrairement à decaissementQueryOptions, scopé à un seul exercice. */
export const executionPluriannuelleQueryOptions = (projetUuid: string | undefined) =>
  queryOptions({
    queryKey: ['execution-pluriannuelle', projetUuid],
    queryFn: () => apiClient.get<ReponseExecutionPluriannuelle>(`/projets/${projetUuid}/execution-pluriannuelle`),
    enabled: projetUuid !== undefined,
  })

export interface Depense {
  uuid: string
  montant: number
  dateDepense: string
  referencePaiement: string | null
  statut: string
}

/** Cible budgétaire : une dotation/un engagement porte sur une activité OU une de ses sous-activités, jamais les deux (§ 5.1, alignement PTBA). */
export interface CibleBudgetaire {
  type: 'activite' | 'sous_activite'
  uuid: string
  libelle: string
}

export interface Dotation {
  uuid: string
  exercice: number
  montant: number
  cible: CibleBudgetaire
  q1: number | null
  q2: number | null
  q3: number | null
  q4: number | null
  montantValideCopil: number | null
  ecartCopil: number | null
  commentaireRevision: string | null
}

export interface Engagement {
  uuid: string
  exercice: number
  referenceMarche: string | null
  nature: string
  montant: number
  dateEngagement: string
  statut: string
  cible: CibleBudgetaire
  depenses: Depense[]
}

/** `activite` XOR `sousActivite` doit être fourni (§ 5.1, alignement PTBA : une ligne budgétaire cible l'un ou l'autre). */
/**
 * Natures d'engagement — miroir de l'enum PHP App\Enum\NatureEngagement,
 * nomenclature des RSE. `groupe` regroupe les natures de fonctionnement dans
 * la liste de saisie.
 */
export const NATURES_ENGAGEMENT: { valeur: string; libelle: string; groupe?: 'Fonctionnement' }[] = [
  { valeur: 'travaux', libelle: 'Travaux (construction, réaménagement…)' },
  { valeur: 'fournitures', libelle: 'Fournitures' },
  { valeur: 'acquisition_biens', libelle: 'Acquisition de biens' },
  { valeur: 'service_consultation', libelle: 'Service de consultation' },
  { valeur: 'service_courant', libelle: 'Service courant' },
  { valeur: 'salaire', libelle: 'Salaire', groupe: 'Fonctionnement' },
  { valeur: 'frais_mission', libelle: 'Frais de mission', groupe: 'Fonctionnement' },
  { valeur: 'atelier', libelle: 'Atelier', groupe: 'Fonctionnement' },
  { valeur: 'remboursement_transport', libelle: 'Remboursement de transport', groupe: 'Fonctionnement' },
  { valeur: 'autre_fonctionnement', libelle: 'Autres natures de fonctionnement', groupe: 'Fonctionnement' },
]

export const LIBELLES_NATURE_ENGAGEMENT: Record<string, string> = Object.fromEntries(
  NATURES_ENGAGEMENT.map((n) => [n.valeur, n.libelle]),
)

export function creerDotation(entree: {
  activite?: string
  sousActivite?: string
  exercice: number
  montant: number
  q1?: number
  q2?: number
  q3?: number
  q4?: number
  montantValideCopil?: number
  commentaireRevision?: string
}) {
  return apiClient.post<{ uuid: string }>('/dotations', entree)
}

export function creerEngagement(entree: {
  activite?: string
  sousActivite?: string
  exercice: number
  nature: string
  montant: number
  dateEngagement: string
  referenceMarche?: string
  observations?: string
}) {
  return apiClient.post<Engagement>('/engagements', entree)
}

export function validerEngagement(uuid: string) {
  return apiClient.post<Engagement>(`/engagements/${uuid}/valider`)
}

export function annulerEngagement(uuid: string) {
  return apiClient.post<Engagement>(`/engagements/${uuid}/annuler`)
}

export function creerDepense(entree: { engagement: string; montant: number; dateDepense: string; referencePaiement?: string }) {
  return apiClient.post<Depense>('/depenses', entree)
}

export function validerDepense(uuid: string) {
  return apiClient.post<Depense>(`/depenses/${uuid}/valider`)
}

export function annulerDepense(uuid: string) {
  return apiClient.post<Depense>(`/depenses/${uuid}/annuler`)
}
