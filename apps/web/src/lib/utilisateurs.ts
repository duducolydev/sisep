import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'

export interface Utilisateur {
  uuid: string
  email: string
  nom: string
  prenom: string
  fonction: string | null
  structure: { uuid: string; sigle: string } | null
  actif: boolean
  doitChangerMdp: boolean
  derniereConnexion: string | null
  roles: { role: string; structure: string | null }[]
}

export const utilisateursQueryOptions = () =>
  queryOptions({
    queryKey: ['admin', 'utilisateurs'],
    queryFn: () => apiClient.get<{ donnees: Utilisateur[] }>('/admin/utilisateurs'),
  })

export interface CreerUtilisateurEntree {
  email: string
  nom: string
  prenom: string
  fonction?: string
  structure?: string
  roles?: { role: string; structure?: string }[]
}

export function creerUtilisateur(entree: CreerUtilisateurEntree) {
  return apiClient.post<Utilisateur & { motDePasseTemporaire: string; courrielEnvoye: boolean }>('/admin/utilisateurs', entree)
}

export interface ModifierUtilisateurEntree {
  nom?: string
  prenom?: string
  fonction?: string | null
  structureUuid?: string | null
  actif?: boolean
}

export function modifierUtilisateur(uuid: string, entree: ModifierUtilisateurEntree) {
  return apiClient.patch<Utilisateur>(`/admin/utilisateurs/${uuid}`, entree)
}

/**
 * Mot de passe oublié : un nouveau mot de passe temporaire, envoyé par
 * courriel et renvoyé une seule fois ici. L'ancien cesse de fonctionner.
 */
export function attribuerMotDePasseTemporaire(uuid: string) {
  return apiClient.post<{ motDePasseTemporaire: string; courrielEnvoye: boolean }>(`/admin/utilisateurs/${uuid}/mot-de-passe-temporaire`)
}

/** Remplace l'ensemble des rôles du compte — `structure` absente : attribution globale. */
export function definirRolesUtilisateur(uuid: string, roles: { role: string; structure: string | null }[]) {
  return apiClient.put<Utilisateur>(`/admin/utilisateurs/${uuid}/roles`, {
    roles: roles.map((r) => ({ role: r.role, ...(r.structure ? { structure: r.structure } : {}) })),
  })
}

export function supprimerUtilisateur(uuid: string) {
  return apiClient.delete<void>(`/admin/utilisateurs/${uuid}`)
}
