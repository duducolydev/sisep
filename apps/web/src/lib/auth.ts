import { queryOptions } from '@tanstack/react-query'
import { apiClient, ApiError } from './api-client'

export interface RoleAttribue {
  code: string
  libelle: string
  structure: string | null
}

export interface ProfilUtilisateur {
  uuid: string
  email: string
  nom: string
  prenom: string
  fonction: string | null
  structure: string | null
  structureUuid: string | null
  doitChangerMdp: boolean
  roles: RoleAttribue[]
  permissions: string[]
  /** Portée de chaque code déjà détenu : `null` = attribution globale, sinon la liste des UUID de structures autorisées (§ 7.2). Hint d'affichage uniquement — le Voter serveur reste seul juge. */
  permissionsPortee: Record<string, string[] | null>
  /** indicateurs.consulter détenu OU ≥ 1 invitation active sur un indicateur précis — hint d'affichage pour le lien de nav (§ 8), revérifié côté serveur. */
  aAccesIndicateurs: boolean
}

/**
 * `null` = non authentifié (401), distingué d'une erreur réseau qui doit
 * remonter normalement.
 */
async function chargerProfil(): Promise<ProfilUtilisateur | null> {
  try {
    return await apiClient.get<ProfilUtilisateur>('/auth/moi')
  } catch (erreur) {
    if (erreur instanceof ApiError && erreur.status === 401) {
      return null
    }
    throw erreur
  }
}

export const profilQueryOptions = () =>
  queryOptions({
    queryKey: ['auth', 'moi'],
    queryFn: chargerProfil,
  })

export async function connecter(email: string, motDePasse: string): Promise<ProfilUtilisateur> {
  const reponse = await apiClient.post<{ utilisateur: ProfilUtilisateur }>('/auth/connexion', {
    email,
    motDePasse,
  })

  return reponse.utilisateur
}

export async function deconnecter(): Promise<void> {
  await apiClient.post('/auth/deconnexion')
}

export async function changerMotDePasse(motDePasseActuel: string, nouveauMotDePasse: string): Promise<void> {
  await apiClient.post('/auth/mot-de-passe', { motDePasseActuel, nouveauMotDePasse })
}
