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
 * Code détenu sur tout le projet, et non sur quelques structures : même
 * règle que PermissionsEffectives::estGlobale, par laquelle le serveur garde
 * l'administration, les ressources humaines, les projets et les parties
 * prenantes. Un rôle d'administrateur limité à une structure n'ouvre aucun de
 * ces écrans : `permissions` le liste pourtant, d'où des onglets proposés puis
 * refusés en 403 tant que l'interface s'en tenait à `permissions.includes`.
 */
export function detientGlobalement(profil: ProfilUtilisateur | null | undefined, code: string): boolean {
  return profil?.permissionsPortee[code] === null
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

/** Toujours accepté (202), qu'un compte corresponde ou non : la réponse ne le dit pas. */
export async function demanderRecuperation(email: string): Promise<void> {
  await apiClient.post('/auth/mot-de-passe-oublie', { email })
}

export async function reinitialiserMotDePasse(jeton: string, nouveauMotDePasse: string): Promise<void> {
  await apiClient.post('/auth/reinitialisation', { jeton, nouveauMotDePasse })
}
