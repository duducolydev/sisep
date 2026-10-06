import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'

export interface Livrable {
  uuid: string
  libelle: string
  unite: string | null
  quantiteAttendue: string
  quantiteRealisee: string
  /** `null` quand la quantité attendue est nulle — jamais 0 (§ 5.1). */
  tauxRealisation: number | null
}

export interface PieceJointeEtape {
  uuid: string
  nomFichier: string
  mime: string
  tailleOctets: number
  creeLe: string
}

export interface EtapeActivite {
  uuid: string
  libelle: string
  ordre: number
  /** Niveau atteint, de 0 à 100. */
  avancement: number
  valeurActuelle: string | null
  unite: string | null
  echeance: string | null
  dateMiseAJour: string | null
  observation: string | null
  livrables: Livrable[]
  piecesJointes: PieceJointeEtape[]
}

export interface EtapeGabarit {
  uuid: string
  libelle: string
  ordre: number
}

export interface GabaritEtapes {
  uuid: string
  libelle: string
  description: string | null
  actif: boolean
  etapes: EtapeGabarit[]
}

export const etapesActiviteQueryOptions = (activiteUuid: string | undefined) =>
  queryOptions({
    queryKey: ['activite', activiteUuid, 'etapes'],
    queryFn: () => apiClient.get<{ donnees: EtapeActivite[] }>(`/activites/${activiteUuid}/etapes`),
    enabled: activiteUuid !== undefined,
  })

/** `tous` inclut les gabarits désactivés — réservé à l'écran d'administration. */
export const gabaritsEtapesQueryOptions = (tous = false) =>
  queryOptions({
    queryKey: ['gabarits-etapes', tous],
    queryFn: () => apiClient.get<{ donnees: GabaritEtapes[] }>(`/gabarits-etapes${tous ? '?tous=1' : ''}`),
    staleTime: 5 * 60_000,
  })

export function creerGabaritEtapes(entree: { libelle: string; description?: string; etapes: string[] }) {
  return apiClient.post<GabaritEtapes>('/gabarits-etapes', entree)
}

export function modifierGabaritEtapes(
  uuid: string,
  entree: { libelle?: string; description?: string | null; actif?: boolean; etapes?: string[] },
) {
  return apiClient.patch<GabaritEtapes>(`/gabarits-etapes/${uuid}`, entree)
}

export function supprimerGabaritEtapes(uuid: string) {
  return apiClient.delete<void>(`/gabarits-etapes/${uuid}`)
}

/** Copie les étapes du gabarit sur l'activité — refusé (409) si elle en a déjà. */
export function instancierGabarit(activiteUuid: string, gabaritUuid: string) {
  return apiClient.post<{ donnees: EtapeActivite[] }>(`/activites/${activiteUuid}/etapes/instancier`, { gabarit: gabaritUuid })
}

export function ajouterEtape(activiteUuid: string, entree: { libelle: string; echeance?: string; unite?: string }) {
  return apiClient.post<EtapeActivite>(`/activites/${activiteUuid}/etapes`, entree)
}

export interface RenseignerEtapeEntree {
  avancement: number
  valeurActuelle?: string | null
  observation?: string | null
  dateMiseAJour: string
}

export function renseignerEtape(uuid: string, entree: RenseignerEtapeEntree) {
  return apiClient.patch<EtapeActivite>(`/etapes/${uuid}`, entree)
}

export function supprimerEtape(uuid: string) {
  return apiClient.delete<void>(`/etapes/${uuid}`)
}

export function creerLivrable(etapeUuid: string, entree: { libelle: string; unite?: string; quantiteAttendue: string }) {
  return apiClient.post<EtapeActivite>(`/etapes/${etapeUuid}/livrables`, entree)
}

/** `quantiteRealisee` relève du terrain ; changer le libellé ou la quantité attendue exige en plus le droit de structurer. */
export function modifierLivrable(
  uuid: string,
  entree: { quantiteRealisee?: string; libelle?: string; unite?: string | null; quantiteAttendue?: string },
) {
  return apiClient.patch<EtapeActivite>(`/livrables/${uuid}`, entree)
}

/** fetch brut multipart — apiClient ne gère pas FormData, même motif que les autres téléversements. */
export async function televerserPieceJointeEtape(etapeUuid: string, fichier: File): Promise<EtapeActivite> {
  const donnees = new FormData()
  donnees.append('fichier', fichier)

  const reponse = await fetch(
    `${(import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'}/etapes/${etapeUuid}/pieces-jointes`,
    { method: 'POST', credentials: 'include', body: donnees },
  )
  if (!reponse.ok) {
    const probleme = (await reponse.json().catch(() => undefined)) as { detail?: string; title?: string } | undefined
    throw new Error(probleme?.detail ?? probleme?.title ?? `Erreur ${reponse.status}`)
  }

  return reponse.json() as Promise<EtapeActivite>
}

export function supprimerLivrable(uuid: string) {
  return apiClient.delete<void>(`/livrables/${uuid}`)
}
