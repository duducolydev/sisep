import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'
import type { Dotation, Engagement } from './budget'

export interface Structure {
  uuid: string
  sigle: string
  denomination: string
  type: string
}

/** Maille purement budgétaire depuis le CR du 22/09/2026 : plus d'avancement, l'arbre n'en expose plus. */
export interface NoeudSousActivite {
  uuid: string
  libelle: string
  statut: string
  echeance: string | null
  notifie: number | null
  engage: number | null
  depense: number | null
}

export interface NoeudActivite {
  uuid: string
  code: string
  libelle: string
  statut: string
  structureResponsable: { uuid: string; sigle: string }
  notifie: number | null
  engage: number | null
  depense: number | null
  /** Progression déduite des étapes (moyenne des niveaux atteints, en %) — `null` si l'activité n'en a aucune. */
  avancement?: number | null
  sousActivites?: NoeudSousActivite[]
}

export interface NoeudSousComposante {
  uuid: string
  code: string
  libelle: string
  notifie: number | null
  engage: number | null
  depense: number | null
  activites?: NoeudActivite[]
}

export interface NoeudComposante {
  uuid: string
  code: string
  libelle: string
  notifie: number | null
  engage: number | null
  depense: number | null
  sousComposantes?: NoeudSousComposante[]
}

export interface ReponseArbre {
  exercice: number
  niveaux: number
  /** Part de l'exercice déjà écoulée à date (§ 5.3) — repère de rythme attendu sur les barres d'exécution. */
  tauxAttendu: number | null
  composantes: NoeudComposante[]
}

export const projetCourantQueryOptions = () =>
  queryOptions({
    queryKey: ['projet', 'courant'],
    queryFn: () => apiClient.get<{ uuid: string; code: string; libelle: string }>('/projets/courant'),
    staleTime: Infinity,
  })

export const arbreQueryOptions = (projetUuid: string | undefined, niveaux: 3 | 4 = 4, exercice?: number) =>
  queryOptions({
    queryKey: ['arbre', projetUuid, niveaux, exercice ?? null],
    queryFn: () =>
      apiClient.get<ReponseArbre>(`/projets/${projetUuid}/arbre?niveaux=${niveaux}${exercice ? `&exercice=${exercice}` : ''}`),
    enabled: projetUuid !== undefined,
  })

export const structuresQueryOptions = () =>
  queryOptions({
    queryKey: ['structures'],
    queryFn: () => apiClient.get<{ donnees: Structure[] }>('/structures'),
    staleTime: 5 * 60_000,
  })

export interface SousActiviteDetail extends NoeudSousActivite {
  dateDebut: string | null
  structureResponsable: { uuid: string; sigle: string } | null
  notifie: number | null
  engage: number | null
  depense: number | null
  dotations: Dotation[]
  engagements: Engagement[]
}

export interface FicheActivite extends Omit<NoeudActivite, 'sousActivites'> {
  description: string | null
  /** Progression déduite des étapes (§ 6 du CR du 22/09/2026) : null tant que l'activité n'est pas structurée, jamais 0. */
  progression: number | null
  etapesTotal: number
  etapesAchevees: number
  dateDebut: string | null
  dateFinPrevue: string | null
  dateFinReelle: string | null
  sousComposante: { uuid: string; code: string; libelle: string }
  sousActivites: SousActiviteDetail[]
  dotations: Dotation[]
  engagements: Engagement[]
  piecesJointes: { uuid: string; nomFichier: string; mime: string }[]
}

export const activiteQueryOptions = (uuid: string | undefined) =>
  queryOptions({
    queryKey: ['activite', uuid],
    queryFn: () => apiClient.get<FicheActivite>(`/activites/${uuid}`),
    enabled: uuid !== undefined,
  })

export interface CreerActiviteEntree {
  sousComposante: string
  code: string
  libelle: string
  structureResponsable: string
  description?: string
}

export function creerActivite(entree: CreerActiviteEntree) {
  return apiClient.post<FicheActivite>('/activites', entree)
}

export interface CreerSousActiviteEntree {
  activite: string
  libelle: string
  ordre?: number
  dateDebut?: string
  echeance?: string
}

export function creerSousActivite(entree: CreerSousActiviteEntree) {
  return apiClient.post<SousActiviteDetail>('/sous-activites', entree)
}

export function modifierStatutActivite(activiteUuid: string, statut: string) {
  return apiClient.patch<FicheActivite>(`/activites/${activiteUuid}`, { statut })
}
