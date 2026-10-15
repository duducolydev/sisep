import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'
import { ajouterNature, type NatureDocument } from './types-document'

/** Calculé (StatutInitiativeCalculator), jamais saisi — fusionne ce qui était auparavant un statut saisi et une vigilance calculée à part. */
export type StatutInitiativeActivite = 'planifiee' | 'en_cours' | 'a_risque' | 'en_retard' | 'terminee'
export type VisibilitePieceJointe = 'tous' | 'equipe_phasaoc' | 'sectoriels' | 'agents_specifiques'

/** Source unique du libellé/ordre des 5 statuts calculés — repris par PastilleStatutInitiative, RepartitionStatutsInitiatives et les vues Tableau/Kanban de la page Projets. */
export const ORDRE_STATUT_INITIATIVE: StatutInitiativeActivite[] = ['planifiee', 'en_cours', 'a_risque', 'en_retard', 'terminee']

export const LIBELLES_STATUT_INITIATIVE: Record<StatutInitiativeActivite, string> = {
  planifiee: 'Planifiée',
  en_cours: 'En cours',
  a_risque: 'À risque',
  en_retard: 'En retard',
  terminee: 'Terminée',
}

/** Mêmes teintes que PastilleStatutInitiative, en var(--color-*) pour les graphiques recharts (fill n'accepte pas les classes Tailwind) — palette validée (scripts/validate_palette.js, skill dataviz), cf. tokens.css. */
export const COULEURS_CSS_STATUT_INITIATIVE: Record<StatutInitiativeActivite, string> = {
  planifiee: 'var(--color-statut-planifiee)',
  en_cours: 'var(--color-accent)',
  a_risque: 'var(--color-bad)',
  en_retard: 'var(--color-eyebrow)',
  terminee: 'var(--color-success)',
}

export interface NoteInitiative {
  uuid: string
  contenu: string
  creeLe: string
  creePar: string | null
}

export interface PieceJointeInitiative {
  uuid: string
  nomFichier: string
  /** Nature choisie au dépôt (« Rapport », « Autre : … ») — null pour les dépôts antérieurs. */
  typeDocumentLibelle: string | null
  mime: string
  tailleOctets: number
  creeLe: string
  visibilite: VisibilitePieceJointe
  agentsVisibilite: { uuid: string; nomComplet: string }[]
}

export type EntiteInitiative = 'initiative_activite' | 'tache_initiative'

export interface TacheInitiative {
  uuid: string
  libelle: string
  ordre: number
  /** Calculé à partir des dates de l'activité parente + dateRealisation — jamais choisi directement. */
  statut: StatutInitiativeActivite
  responsable: { uuid: string; nomComplet: string } | null
  dateRealisation: string | null
  notes: NoteInitiative[]
  piecesJointes: PieceJointeInitiative[]
}

export interface InitiativeActivite {
  uuid: string
  libelle: string
  dateDebut: string | null
  echeance: string | null
  /** Calculé (StatutInitiativeCalculator) à partir des dates et de l'avancement des tâches — jamais choisi directement. */
  statut: StatutInitiativeActivite
  /** Dérivée : max(dateRealisation) des tâches quand avancement === 1, sinon null. */
  dateReelle: string | null
  /** Tâches faites / total, calculé à l'affichage — null si l'activité n'a aucune tâche. */
  avancement: number | null
  responsables: { uuid: string; nomComplet: string }[]
  notes: NoteInitiative[]
  piecesJointes: PieceJointeInitiative[]
  taches: TacheInitiative[]
}

export interface LienPtba {
  type: 'composante' | 'sous_composante'
  uuid: string
  code: string
  libelle: string
  tauxExecutionPtba: number | null
}

export interface Initiative {
  uuid: string
  libelle: string
  description: string | null
  lienPtba: LienPtba | null
  creePar: { uuid: string; nomComplet: string }
  activites: InitiativeActivite[]
}

/** Une activité à plat, avec son projet parent en contexte — statut/responsable/échéance n'existant qu'au niveau activité, les vues Tableau/Kanban de la page Projets travaillent sur cette forme plutôt que sur Initiative directement. */
export interface ActiviteAvecProjet extends InitiativeActivite {
  initiativeUuid: string
  initiativeLibelle: string
  initiativeLienPtba: LienPtba | null
}

export const initiativesQueryOptions = () =>
  queryOptions({
    queryKey: ['initiatives'],
    queryFn: () => apiClient.get<{ donnees: Initiative[] }>('/initiatives'),
  })

export const responsablesEligiblesQueryOptions = () =>
  queryOptions({
    queryKey: ['initiatives', 'responsables-eligibles'],
    queryFn: () => apiClient.get<{ donnees: { uuid: string; nomComplet: string }[] }>('/initiatives/responsables-eligibles'),
  })

export const initiativeQueryOptions = (uuid: string | undefined) =>
  queryOptions({
    queryKey: ['initiatives', 'detail', uuid],
    queryFn: () => apiClient.get<Initiative>(`/initiatives/${uuid}`),
    enabled: uuid !== undefined,
  })

export interface CreerInitiativeEntree {
  libelle: string
  description?: string
  composante?: string
  sousComposante?: string
}

export function creerInitiative(entree: CreerInitiativeEntree) {
  return apiClient.post<Initiative>('/initiatives', entree)
}

export function supprimerInitiative(uuid: string) {
  return apiClient.delete<void>(`/initiatives/${uuid}`)
}

export interface CreerInitiativeActiviteEntree {
  initiative: string
  libelle: string
  dateDebut?: string
  echeance?: string
  responsables?: string[]
}

export function creerInitiativeActivite(entree: CreerInitiativeActiviteEntree) {
  return apiClient.post<Initiative>('/initiative-activites', entree)
}

export interface CreerTacheInitiativeEntree {
  initiativeActivite: string
  libelle: string
  responsable?: string
}

export function creerTacheInitiative(entree: CreerTacheInitiativeEntree) {
  return apiClient.post<Initiative>('/taches-initiative', entree)
}

export function definirTacheTerminee(uuid: string, terminee: boolean) {
  return apiClient.patch<Initiative>(`/taches-initiative/${uuid}`, { terminee })
}

export function supprimerTacheInitiative(uuid: string) {
  return apiClient.delete<void>(`/taches-initiative/${uuid}`)
}

export function ajouterNoteInitiative(entiteType: EntiteInitiative, entiteUuid: string, contenu: string) {
  return apiClient.post<Initiative>('/initiative-notes', { entiteType, entiteUuid, contenu })
}

/** fetch brut multipart — apiClient ne gère pas FormData, même motif que transmettreRapport() dans lib/parties-prenantes.ts. */
export async function televerserPieceJointeInitiative(
  entiteType: EntiteInitiative,
  entiteUuid: string,
  fichier: File,
  visibilite: VisibilitePieceJointe,
  agentsUuid: string[],
  nature: NatureDocument,
): Promise<Initiative> {
  const donnees = new FormData()
  ajouterNature(donnees, nature)
  donnees.append('entiteType', entiteType)
  donnees.append('entiteUuid', entiteUuid)
  donnees.append('fichier', fichier)
  donnees.append('visibilite', visibilite)
  agentsUuid.forEach((uuid) => donnees.append('agentsUuid[]', uuid))

  const reponse = await fetch(`${(import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'}/initiative-pieces-jointes`, {
    method: 'POST',
    credentials: 'include',
    body: donnees,
  })
  if (!reponse.ok) {
    const probleme = (await reponse.json().catch(() => undefined)) as { detail?: string; title?: string } | undefined
    throw new Error(probleme?.detail ?? probleme?.title ?? `Erreur ${reponse.status}`)
  }

  return reponse.json() as Promise<Initiative>
}

export interface CriteresProjets {
  recherche: string
  /** `tous`, `independant` ou `lie`. */
  lienPtba: string
  /** Vrai si un filtre porte sur les activités elles-mêmes (statut, responsable). */
  filtresSurActivites: boolean
}

/**
 * Projets à afficher, à partir des activités déjà filtrées.
 *
 * Un projet ne se déduit pas de ses activités : un projet qu'on vient de
 * créer n'en a aucune, et le dériver des activités le faisait disparaître
 * de toute la page — compteur à zéro, aucune carte, donc aucun moyen de
 * l'ouvrir pour y ajouter sa première activité.
 *
 * Les filtres sur les activités (statut, responsable) peuvent légitimement
 * masquer un projet : on ne garde alors que ceux qui ont au moins une
 * activité correspondante. Sans eux, un projet vide reste visible, sous
 * réserve de la recherche, qui porte alors sur son intitulé.
 */
export function filtrerProjets(
  initiatives: Initiative[],
  activitesFiltrees: Pick<ActiviteAvecProjet, 'initiativeUuid'>[],
  criteres: CriteresProjets,
): Initiative[] {
  const recherche = criteres.recherche.trim().toLowerCase()
  const avecActiviteRetenue = new Set(activitesFiltrees.map((a) => a.initiativeUuid))

  return initiatives.filter((i) => {
    if (criteres.lienPtba === 'independant' && null !== i.lienPtba) return false
    if (criteres.lienPtba === 'lie' && null === i.lienPtba) return false
    if (avecActiviteRetenue.has(i.uuid)) return true
    if (criteres.filtresSurActivites) return false

    return recherche === '' || i.libelle.toLowerCase().includes(recherche)
  })
}
