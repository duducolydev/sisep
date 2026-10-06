import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'

export type Sexe = 'M' | 'F'

export const LIBELLES_SEXE: Record<Sexe, string> = {
  F: 'Femmes',
  M: 'Hommes',
}

/**
 * Deux teintes catégorielles pour la désagrégation par sexe — bleu et ocre,
 * séparés en teinte comme en clarté, le couple qui reste lisible sous
 * daltonisme. Volontairement distinctes des couleurs de statut, qui sont
 * réservées et ne doivent jamais servir de série.
 */
export const COULEURS_SEXE: Record<Sexe, string> = {
  F: 'var(--color-serie-1)',
  M: 'var(--color-serie-2)',
}

/** Au PHASAOC, tout le personnel technique est sous contrat de prestation (confirmé par l'UGP) : la saisie part de cette valeur. */
export const TYPE_CONTRAT_PAR_DEFAUT = 'Contrat de prestation'

/**
 * Faute de dates connues agent par agent, l'UGP a arrêté la convention de
 * l'exercice complet (demande du 05/10/2026). Calculé sur l'année courante
 * plutôt que figé sur 2026 : la convention vaudra encore l'an prochain.
 */
export function exerciceParDefaut(): { dateDebut: string; dateFin: string } {
  const annee = new Date().getFullYear()

  return { dateDebut: `${annee}-01-01`, dateFin: `${annee}-12-31` }
}

export interface Contrat {
  uuid: string
  dateDebut: string
  dateFin: string | null
  type: string | null
  observation: string | null
  dureeEnJours: number | null
  /** Négatif si l'échéance est passée, `null` si le contrat n'a pas de terme. */
  joursAvantEcheance: number | null
}

export interface Personnel {
  uuid: string
  nom: string
  prenom: string
  nomComplet: string
  sexe: Sexe
  fonction: string
  profil: string | null
  specialite: string | null
  email: string | null
  telephone: string | null
  structure: { uuid: string; sigle: string } | null
  activite: { uuid: string; code: string; libelle: string } | null
  contratCourant: Contrat | null
  contrats: Contrat[]
}

export interface EffectifEnqueteurs {
  uuid: string
  activite: { uuid: string; code: string; libelle: string }
  libelle: string | null
  annee: number
  /** Période de mobilisation — `null` tant que le calendrier de collecte n'est pas arrêté. */
  dateDebut: string | null
  dateFin: string | null
  /** Déduite des deux bornes, jamais saisie ; `null` si l'une manque. */
  dureeEnJours: number | null
  nombreFemmes: number
  nombreHommes: number
  /** Déduit de la somme des deux, jamais saisi. */
  effectifTotal: number
  /** `null` quand l'effectif est vide (§ 5.1). */
  partFemmes: number | null
}

export interface EcheanceContrat {
  personnel: { uuid: string; nomComplet: string; fonction: string }
  contrat: Contrat
}

export const personnelsQueryOptions = () =>
  queryOptions({
    queryKey: ['rh', 'personnels'],
    queryFn: () => apiClient.get<{ donnees: Personnel[] }>('/rh/personnels'),
  })

export const effectifsEnqueteursQueryOptions = () =>
  queryOptions({
    queryKey: ['rh', 'effectifs-enqueteurs'],
    queryFn: () => apiClient.get<{ donnees: EffectifEnqueteurs[] }>('/rh/effectifs-enqueteurs'),
  })

export const echeancesContratsQueryOptions = (jours = 90) =>
  queryOptions({
    queryKey: ['rh', 'echeances', jours],
    queryFn: () => apiClient.get<{ jours: number; donnees: EcheanceContrat[] }>(`/rh/echeances?jours=${jours}`),
  })

export interface PersonnelEntree {
  nom: string
  prenom: string
  sexe: Sexe
  fonction: string
  profil?: string | null
  specialite?: string | null
  email?: string | null
  telephone?: string | null
  structure?: string | null
  activite?: string | null
  /**
   * Période du contrat, saisie depuis la fiche de l'agent : à la création
   * elle ouvre le premier contrat, à la modification elle corrige celui en
   * cours. Un renouvellement passe par `creerContrat`, qui ajoute une ligne.
   */
  contrat?: { dateDebut: string; dateFin?: string | null }
}

export function creerPersonnel(entree: PersonnelEntree) {
  return apiClient.post<Personnel>('/rh/personnels', entree)
}

export function modifierPersonnel(uuid: string, entree: Partial<PersonnelEntree>) {
  return apiClient.patch<Personnel>(`/rh/personnels/${uuid}`, entree)
}

export function supprimerPersonnel(uuid: string) {
  return apiClient.delete<void>(`/rh/personnels/${uuid}`)
}

export interface ContratEntree {
  dateDebut: string
  dateFin?: string | null
  type?: string | null
  observation?: string | null
}

/** Un renouvellement se saisit comme un nouveau contrat, jamais en prolongeant le précédent. */
export function creerContrat(personnelUuid: string, entree: ContratEntree) {
  return apiClient.post<Personnel>(`/rh/personnels/${personnelUuid}/contrats`, entree)
}

export function modifierContrat(uuid: string, entree: Partial<ContratEntree>) {
  return apiClient.patch<Contrat>(`/rh/contrats/${uuid}`, entree)
}

export function supprimerContrat(uuid: string) {
  return apiClient.delete<void>(`/rh/contrats/${uuid}`)
}

export interface EffectifEntree {
  activite: string
  annee: number
  libelle?: string | null
  dateDebut?: string | null
  dateFin?: string | null
  nombreFemmes?: number
  nombreHommes?: number
}

export function creerEffectifEnqueteurs(entree: EffectifEntree) {
  return apiClient.post<EffectifEnqueteurs>('/rh/effectifs-enqueteurs', entree)
}

export function modifierEffectifEnqueteurs(uuid: string, entree: Partial<EffectifEntree>) {
  return apiClient.patch<EffectifEnqueteurs>(`/rh/effectifs-enqueteurs/${uuid}`, entree)
}

export function supprimerEffectifEnqueteurs(uuid: string) {
  return apiClient.delete<void>(`/rh/effectifs-enqueteurs/${uuid}`)
}

/** Agrège une liste d'agents sur un axe donné — sert aux graphiques du tableau de bord (profils, spécialités). */
export function compterPar(personnels: Personnel[], axe: (p: Personnel) => string | null): { libelle: string; nombre: number }[] {
  const compteurs = new Map<string, number>()
  for (const personnel of personnels) {
    const cle = axe(personnel) ?? 'Non renseigné'
    compteurs.set(cle, (compteurs.get(cle) ?? 0) + 1)
  }

  return [...compteurs.entries()]
    .map(([libelle, nombre]) => ({ libelle, nombre }))
    .sort((a, b) => b.nombre - a.nombre)
}
