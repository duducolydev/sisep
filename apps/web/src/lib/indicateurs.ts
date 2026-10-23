import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'
import { ajouterNature, type NatureDocument } from './types-document'
import type { PieceRequalifiable } from './documents'

export type TypeIndicateur = 'IOD' | 'IRI'
export type SensIndicateur = 'croissant' | 'decroissant'

export interface Indicateur {
  uuid: string
  code: string
  libelle: string
  precisionLibelle: string | null
  type: TypeIndicateur
  composante: { uuid: string; code: string } | null
  unite: string
  sens: SensIndicateur
  valeurReference: string
  dateReference: string
  valeurIntermediaire: string | null
  dateIntermediaire: string | null
  valeurCible: string
  dateCible: string
  frequence: string
  source: string
  structureResponsable: { uuid: string; sigle: string } | null
  actif: boolean
  actuel: string | null
  periodeActuelle: string | null
  tauxAtteinte: number | null
  /** Hint serveur (§ 8) : indicateurs.saisir (portée-aware) OU une invitation active pour l'utilisateur courant sur cet indicateur précis. */
  peutSaisir: boolean
  /**
   * Saisie la plus récente quand elle attend encore sa validation, avec son
   * taux d'atteinte provisoire — affichée aussitôt (retours des RSE), sans
   * remplacer `actuel`/`tauxAtteinte`, qui restent les valeurs validées.
   */
  enAttente: { uuid: string; valeur: string; periode: string; tauxAtteinte: number | null } | null
}

/** Valeur du filtre `composante` pour les indicateurs transversaux, rattachés à aucune composante. */
export const OBJECTIF_GENERAL = 'general'

/** Libellé du rattachement d'un indicateur : sa composante, ou l'objectif général s'il n'en a pas. */
export function libelleRattachement(indicateur: Pick<Indicateur, 'composante'>): string {
  return indicateur.composante === null ? 'Objectif général' : `Composante ${indicateur.composante.code}`
}

/** Valeur à montrer comme « actuelle » : la saisie en attente si elle existe, sinon la dernière validée. */
export function valeurAffichee(indicateur: Pick<Indicateur, 'actuel' | 'periodeActuelle' | 'tauxAtteinte' | 'enAttente'>) {
  if (indicateur.enAttente !== null) {
    return {
      valeur: indicateur.enAttente.valeur,
      periode: indicateur.enAttente.periode,
      tauxAtteinte: indicateur.enAttente.tauxAtteinte,
      enAttente: true,
    }
  }

  return {
    valeur: indicateur.actuel,
    periode: indicateur.periodeActuelle,
    tauxAtteinte: indicateur.tauxAtteinte,
    enAttente: false,
  }
}

export interface Desagregation {
  dimension: string
  modalite: string
  valeur: string
}

export interface PieceJointeIndicateur extends PieceRequalifiable {
  mime: string
  tailleOctets: number
  creeLe: string
}

export interface IndicateurValeur {
  uuid: string
  periode: string
  valeur: string
  commentaire: string | null
  statut: string
  desagregations: Desagregation[]
  piecesJointes: PieceJointeIndicateur[]
}

export const indicateursQueryOptions = (type?: TypeIndicateur, composanteUuid?: string, structureResponsableUuid?: string) =>
  queryOptions({
    queryKey: ['indicateurs', type ?? null, composanteUuid ?? null, structureResponsableUuid ?? null],
    queryFn: () => {
      const params = new URLSearchParams()
      if (type) params.set('type', type)
      if (composanteUuid) params.set('composante', composanteUuid)
      if (structureResponsableUuid) params.set('structureResponsable', structureResponsableUuid)
      const suffixe = params.toString()
      return apiClient.get<{ donnees: Indicateur[] }>(`/indicateurs${suffixe ? `?${suffixe}` : ''}`)
    },
  })

export const indicateurQueryOptions = (uuid: string | undefined) =>
  queryOptions({
    queryKey: ['indicateur', uuid],
    queryFn: () => apiClient.get<Indicateur>(`/indicateurs/${uuid}`),
    enabled: uuid !== undefined,
  })

export const indicateurValeursQueryOptions = (uuid: string | undefined) =>
  queryOptions({
    queryKey: ['indicateur', uuid, 'valeurs'],
    queryFn: () => apiClient.get<{ donnees: IndicateurValeur[] }>(`/indicateurs/${uuid}/valeurs`),
    enabled: uuid !== undefined,
  })

export interface CreerIndicateurEntree {
  code: string
  libelle: string
  precisionLibelle?: string
  type: TypeIndicateur
  composante?: string
  unite: string
  sens: SensIndicateur
  valeurReference: string
  dateReference: string
  valeurIntermediaire?: string
  dateIntermediaire?: string
  valeurCible: string
  dateCible: string
  frequence: string
  source: string
  structureResponsable?: string
}

export function creerIndicateur(entree: CreerIndicateurEntree) {
  return apiClient.post<Indicateur>('/indicateurs', entree)
}

export interface ModifierIndicateurEntree {
  libelle?: string
  precisionLibelle?: string | null
  unite?: string
  valeurIntermediaire?: string | null
  dateIntermediaire?: string | null
  valeurCible?: string
  dateCible?: string
  source?: string
  actif?: boolean
  /** UUID de composante, ou `null` pour l'objectif général. */
  composante?: string | null
}

export function modifierIndicateur(uuid: string, entree: ModifierIndicateurEntree) {
  return apiClient.patch<Indicateur>(`/indicateurs/${uuid}`, entree)
}

/** Exactement l'un de valeur (absolue) ou delta (ajustement +/- depuis la dernière saisie) doit être fourni. */
export interface SaisirValeurEntree {
  periode: string
  valeur?: string
  delta?: string
  commentaire?: string
  desagregations: Desagregation[]
}

export function saisirValeur(indicateurUuid: string, entree: SaisirValeurEntree) {
  return apiClient.post<IndicateurValeur>(`/indicateurs/${indicateurUuid}/valeurs`, entree)
}

export function validerValeur(uuid: string) {
  return apiClient.post<IndicateurValeur>(`/indicateurs/valeurs/${uuid}/valider`)
}

/** fetch brut multipart — apiClient ne gère pas FormData, même motif que televerserPieceJointeInitiative(). */
export async function televerserPieceJointeValeur(valeurUuid: string, fichier: File, nature: NatureDocument): Promise<IndicateurValeur> {
  const donnees = new FormData()
  donnees.append('fichier', fichier)
  ajouterNature(donnees, nature)

  const reponse = await fetch(`${(import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'}/indicateurs/valeurs/${valeurUuid}/pieces-jointes`, {
    method: 'POST',
    credentials: 'include',
    body: donnees,
  })
  if (!reponse.ok) {
    const probleme = (await reponse.json().catch(() => undefined)) as { detail?: string; title?: string } | undefined
    throw new Error(probleme?.detail ?? probleme?.title ?? `Erreur ${reponse.status}`)
  }

  return reponse.json() as Promise<IndicateurValeur>
}

export interface UtilisateurEligible {
  uuid: string
  nomComplet: string
}

export const sectorielsEligiblesQueryOptions = () =>
  queryOptions({
    queryKey: ['indicateurs', 'sectoriels-eligibles'],
    queryFn: () => apiClient.get<{ donnees: UtilisateurEligible[] }>('/indicateurs/sectoriels-eligibles'),
  })

export interface Invitation {
  uuid: string
  utilisateur: UtilisateurEligible
  invitePar: string
  inviteLe: string
}

export const invitationsQueryOptions = (indicateurUuid: string | undefined) =>
  queryOptions({
    queryKey: ['indicateur', indicateurUuid, 'invitations'],
    queryFn: () => apiClient.get<{ donnees: Invitation[] }>(`/indicateurs/${indicateurUuid}/invitations`),
    enabled: indicateurUuid !== undefined,
  })

export function inviterSectoriel(indicateurUuid: string, utilisateurUuid: string) {
  return apiClient.post<Invitation>(`/indicateurs/${indicateurUuid}/invitations`, { utilisateurUuid })
}

export function revoquerInvitation(uuid: string) {
  return apiClient.delete<void>(`/indicateurs/invitations/${uuid}`)
}
