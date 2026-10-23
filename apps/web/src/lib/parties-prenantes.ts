import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'
import type { Structure } from './composantes'

export type TypeStructure =
  | 'chef_de_file'
  | 'direction_ansd'
  | 'cellule_ansd'
  | 'partenaire_national'
  | 'partenaire_technique'
  | 'partenaire_formation'
  | 'bailleur'

export interface StructureDetail extends Omit<Structure, 'type'> {
  type: TypeStructure
  telephone: string | null
  email: string | null
  adresse: string | null
  actif: boolean
}

export const structuresDetailQueryOptions = (toutes = false) =>
  queryOptions({
    queryKey: ['structures', 'detail', toutes],
    queryFn: () => apiClient.get<{ donnees: StructureDetail[] }>(`/structures${toutes ? '?toutes=1' : ''}`),
  })

export const structureQueryOptions = (uuid: string | undefined) =>
  queryOptions({
    queryKey: ['structures', uuid],
    queryFn: () => apiClient.get<StructureDetail>(`/structures/${uuid}`),
    enabled: uuid !== undefined,
  })

export interface ActiviteDeStructure {
  uuid: string
  code: string
  libelle: string
  statut: string
  sousComposante: { uuid: string; code: string; libelle: string }
}

export const structureActivitesQueryOptions = (uuid: string | undefined) =>
  queryOptions({
    queryKey: ['structures', uuid, 'activites'],
    queryFn: () => apiClient.get<{ donnees: ActiviteDeStructure[] }>(`/structures/${uuid}/activites`),
    enabled: uuid !== undefined,
  })

export interface CreerStructureEntree {
  sigle: string
  denomination: string
  type: TypeStructure
  /** Obligatoire : destinataire du système d'alertes par courriel. */
  email: string
  telephone?: string
  adresse?: string
}

export function creerStructure(entree: CreerStructureEntree) {
  return apiClient.post<StructureDetail>('/structures', entree)
}

export function modifierStructure(uuid: string, entree: Partial<CreerStructureEntree> & { actif?: boolean }) {
  return apiClient.patch<StructureDetail>(`/structures/${uuid}`, entree)
}

export function supprimerStructure(uuid: string) {
  return apiClient.delete<void>(`/structures/${uuid}`)
}

export interface PointFocal {
  uuid: string
  structure: { uuid: string; sigle: string }
  nomComplet: string
  fonction: string
  telephone: string | null
  email: string | null
  principal: boolean
  dateDesignation: string | null
  actif: boolean
  utilisateur: { uuid: string; email: string } | null
}

export const pointsFocauxQueryOptions = (structureUuid?: string) =>
  queryOptions({
    queryKey: ['points-focaux', structureUuid ?? null],
    queryFn: () => apiClient.get<{ donnees: PointFocal[] }>(`/points-focaux${structureUuid ? `?structure=${structureUuid}` : ''}`),
  })

export interface CreerPointFocalEntree {
  structure: string
  nomComplet: string
  fonction: string
  telephone?: string
  email?: string
  principal?: boolean
  dateDesignation?: string
}

export function creerPointFocal(entree: CreerPointFocalEntree) {
  return apiClient.post<PointFocal>('/points-focaux', entree)
}

/** `null` vide le champ (téléphone, e-mail, date de désignation). */
export interface ModifierPointFocalEntree {
  nomComplet?: string
  fonction?: string
  telephone?: string | null
  email?: string | null
  principal?: boolean
  dateDesignation?: string | null
  actif?: boolean
}

export function modifierPointFocal(uuid: string, entree: ModifierPointFocalEntree) {
  return apiClient.patch<PointFocal>(`/points-focaux/${uuid}`, entree)
}

export function lierCompteUtilisateur(pointFocalUuid: string, utilisateurUuid: string | null) {
  return apiClient.patch<PointFocal>(`/points-focaux/${pointFocalUuid}`, { utilisateurUuid })
}

export interface Rapport {
  uuid: string
  structure: { uuid: string; sigle: string; denomination: string }
  exercice: number
  periode: 'T1' | 'T2' | 'T3' | 'T4'
  statut: 'attendu' | 'transmis' | 'valide' | 'rejete'
  enRetard: boolean
  statutAffiche: 'attendu' | 'transmis' | 'valide' | 'rejete' | 'retard'
  dateEcheance: string
  dateTransmission: string | null
  pieceJointe: { uuid: string; nomFichier: string } | null
  commentaire: string | null
  derniereRelanceLe: string | null
}

export const rapportsQueryOptions = (filtres: { exercice?: number; periode?: string; statut?: string } = {}) =>
  queryOptions({
    queryKey: ['rapports', filtres],
    queryFn: () => {
      const params = new URLSearchParams()
      if (filtres.exercice) params.set('exercice', String(filtres.exercice))
      if (filtres.periode) params.set('periode', filtres.periode)
      if (filtres.statut) params.set('statut', filtres.statut)
      const suffixe = params.toString()
      return apiClient.get<{ donnees: Rapport[] }>(`/rapports${suffixe ? `?${suffixe}` : ''}`)
    },
  })

export function relancerRapports(structures: string[]) {
  return apiClient.post<{ relances: number; courriels: number; sansDestinataire: number }>('/rapports/relancer', { structures })
}

export async function transmettreRapport(uuid: string, fichier: File): Promise<Rapport> {
  const donnees = new FormData()
  donnees.append('fichier', fichier)

  const reponse = await fetch(`${(import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'}/rapports/${uuid}/transmettre`, {
    method: 'POST',
    credentials: 'include',
    body: donnees,
  })
  if (!reponse.ok) {
    const probleme = (await reponse.json().catch(() => undefined)) as { detail?: string; title?: string } | undefined
    throw new Error(probleme?.detail ?? probleme?.title ?? `Erreur ${reponse.status}`)
  }

  return reponse.json() as Promise<Rapport>
}
