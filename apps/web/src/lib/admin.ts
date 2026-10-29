import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'
import { detientGlobalement, type ProfilUtilisateur } from './auth'

export interface OngletAdministration {
  to: string
  label: string
  /** `null` = onglet ouvert à tout utilisateur authentifié. */
  permission: string | null
}

/**
 * Chaque onglet porte la permission qui ouvre réellement sa page. Sans cela,
 * un responsable suivi-évaluation voyait « Comptes utilisateurs » et « Rôles
 * et permissions », cliquait, et tombait sur un 403 : le serveur refusait à
 * juste titre, mais l'interface n'aurait jamais dû proposer le chemin.
 *
 * `null` : la liste des structures est lisible par tous ; seule sa
 * modification est gardée, dans la page elle-même.
 */
const ONGLETS_ADMINISTRATION: OngletAdministration[] = [
  { to: '/administration/indicateurs', label: 'Référentiel des indicateurs de performance', permission: 'indicateurs.definir' },
  { to: '/administration/modeles-etapes', label: "Modèles d'étapes", permission: 'admin.parametres' },
  { to: '/administration/parties-prenantes', label: 'Parties prenantes', permission: null },
  { to: '/administration/comptes', label: 'Comptes utilisateurs', permission: 'admin.utilisateurs' },
  { to: '/administration/roles', label: 'Rôles et permissions', permission: 'admin.roles' },
  { to: '/administration/journal', label: "Journal d'activité", permission: 'journal.consulter' },
  { to: '/administration/parametres', label: 'Paramètres', permission: 'admin.parametres' },
]

/**
 * Onglets que ce profil peut réellement ouvrir. Le serveur ne sert ces pages
 * qu'à une attribution globale (exigerPermissionGlobale) : un rôle limité à
 * une structure ne compte pas, même s'il porte le code.
 */
export function ongletsAdministration(profil: ProfilUtilisateur | null | undefined): OngletAdministration[] {
  return ONGLETS_ADMINISTRATION.filter((o) => o.permission === null || detientGlobalement(profil, o.permission))
}

/**
 * Le lien « Administration » du menu : affiché dès qu'un onglet gardé est
 * ouvert à ce profil, et pointé sur le premier d'entre eux. Les onglets
 * ouverts à tous ne suffisent pas à le faire apparaître.
 */
export function entreeAdministration(profil: ProfilUtilisateur | null | undefined): string | null {
  const onglets = ongletsAdministration(profil)

  return onglets.some((o) => o.permission !== null) ? onglets[0].to : null
}

export interface Role {
  uuid: string
  code: string
  libelle: string
  description: string | null
  systeme: boolean
  permissions: string[]
}

export const rolesQueryOptions = () =>
  queryOptions({
    queryKey: ['admin', 'roles'],
    queryFn: () => apiClient.get<{ donnees: Role[] }>('/admin/roles'),
  })

export interface Permission {
  uuid: string
  code: string
  groupe: string
  libelle: string
}

export const permissionsQueryOptions = () =>
  queryOptions({
    queryKey: ['admin', 'permissions'],
    queryFn: () => apiClient.get<{ donnees: Permission[] }>('/admin/permissions'),
  })

export function modifierRolePermissions(roleUuid: string, permissions: string[]) {
  return apiClient.put<Role>(`/admin/roles/${roleUuid}/permissions`, { permissions })
}

export interface Parametre {
  code: string
  valeur: string
  description: string | null
  modifieLe: string | null
}

export const parametresQueryOptions = () =>
  queryOptions({
    queryKey: ['admin', 'parametres'],
    queryFn: () => apiClient.get<{ donnees: Parametre[] }>('/admin/parametres'),
  })

export function modifierParametres(valeurs: Record<string, string>) {
  return apiClient.put<{ donnees: Parametre[] }>('/admin/parametres', valeurs)
}

export interface EntreeJournal {
  id: string
  utilisateur: string | null
  action: string
  entiteType: string
  entiteId: number | null
  avant: Record<string, unknown> | null
  apres: Record<string, unknown> | null
  adresseIp: string
  creeLe: string
}

export interface FiltresJournal {
  entite?: string
  utilisateur?: string
  du?: string
  au?: string
  page?: number
}

/** Référence lisible d'une écriture budgétaire — absente si la ligne a été supprimée depuis. */
export interface ReferenceEcriture {
  activite: { code: string; libelle: string }
  exercice: number
  /** Chaîne : un montant FCFA dépasse la précision sûre d'un nombre JSON. */
  montant: string
  libelle: string | null
  statut: string | null
}

export interface EntreeJournalBudget extends EntreeJournal {
  reference: ReferenceEcriture | null
}

export interface FiltresJournalBudget {
  /** `dotation`, `engagement` ou `depense` ; absent = les trois. */
  type?: string
  utilisateur?: string
  du?: string
  au?: string
  page?: number
}

/**
 * Fil des écritures budgétaires, consultable depuis l'écran du budget —
 * contrepartie de la dérogation qui laisse le responsable suivi-évaluation
 * saisir et valider (§ 7.2).
 */
export const journalBudgetQueryOptions = (filtres: FiltresJournalBudget = {}) =>
  queryOptions({
    queryKey: ['journal', 'budget', filtres],
    queryFn: () => {
      const params = new URLSearchParams()
      if (filtres.type) params.set('type', filtres.type)
      if (filtres.utilisateur) params.set('utilisateur', filtres.utilisateur)
      if (filtres.du) params.set('du', filtres.du)
      if (filtres.au) params.set('au', filtres.au)
      params.set('page', String(filtres.page ?? 1))

      return apiClient.get<{
        donnees: EntreeJournalBudget[]
        pagination: { page: number; taille: number; total: number; pages: number }
      }>(`/journal/budget?${params.toString()}`)
    },
  })

export const journalQueryOptions = (filtres: FiltresJournal = {}) =>
  queryOptions({
    queryKey: ['admin', 'journal', filtres],
    queryFn: () => {
      const params = new URLSearchParams()
      if (filtres.entite) params.set('entite', filtres.entite)
      if (filtres.utilisateur) params.set('utilisateur', filtres.utilisateur)
      if (filtres.du) params.set('du', filtres.du)
      if (filtres.au) params.set('au', filtres.au)
      params.set('page', String(filtres.page ?? 1))
      return apiClient.get<{
        donnees: EntreeJournal[]
        pagination: { page: number; taille: number; total: number; pages: number }
      }>(`/journal?${params.toString()}`)
    },
  })
