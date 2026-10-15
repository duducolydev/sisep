import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'
import { ajouterNature, type NatureDocument } from './types-document'

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
  /** Nature choisie au dépôt (« Rapport », « Autre : … ») — null pour les dépôts antérieurs. */
  typeDocumentLibelle: string | null
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

/** Chemin des étapes d'une activité (étapes propres) ou d'une de ses lignes (sous-activité). */
function cheminEtapes(activiteUuid: string | undefined, sousActiviteUuid?: string): string {
  return sousActiviteUuid !== undefined ? `/sous-activites/${sousActiviteUuid}/etapes` : `/activites/${activiteUuid}/etapes`
}

/**
 * Clé rangée sous ['activite', uuid] : toute écriture sur une étape invalide
 * la fiche de l'activité, ses étapes propres et celles de ses lignes d'un
 * même geste.
 */
export const etapesActiviteQueryOptions = (activiteUuid: string | undefined, sousActiviteUuid?: string) =>
  queryOptions({
    queryKey: ['activite', activiteUuid, 'etapes', sousActiviteUuid ?? 'propres'],
    queryFn: () => apiClient.get<{ donnees: EtapeActivite[] }>(cheminEtapes(activiteUuid, sousActiviteUuid)),
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

/** Copie les étapes du gabarit sur l'activité ou sur une ligne — refusé (409) si elle en a déjà. */
export function instancierGabarit(activiteUuid: string, gabaritUuid: string, sousActiviteUuid?: string) {
  return apiClient.post<{ donnees: EtapeActivite[] }>(`${cheminEtapes(activiteUuid, sousActiviteUuid)}/instancier`, {
    gabarit: gabaritUuid,
  })
}

export function ajouterEtape(
  activiteUuid: string,
  entree: { libelle: string; echeance?: string; unite?: string },
  sousActiviteUuid?: string,
) {
  return apiClient.post<EtapeActivite>(cheminEtapes(activiteUuid, sousActiviteUuid), entree)
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
export async function televerserPieceJointeEtape(etapeUuid: string, fichier: File, nature: NatureDocument): Promise<EtapeActivite> {
  const donnees = new FormData()
  donnees.append('fichier', fichier)
  ajouterNature(donnees, nature)

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
