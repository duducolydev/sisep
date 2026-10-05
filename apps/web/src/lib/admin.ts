import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'

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
