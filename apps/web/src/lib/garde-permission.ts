import { redirect } from 'react-router'
import { profilQueryOptions } from './auth'
import { queryClient } from './query-client'

/**
 * Garde de route (loader) — même idiome que le loader d'authentification
 * racine de router.tsx. Redirige silencieusement si la permission n'est pas
 * détenue ; le contrôle qui compte reste côté serveur (PermissionVoter).
 */
export function gardePermission(code: string) {
  return async () => {
    const profil = await queryClient.ensureQueryData(profilQueryOptions())
    if (profil !== null && !profil.permissions.includes(code)) {
      throw redirect('/tableau-de-bord')
    }

    return null
  }
}

/**
 * Garde des routes /indicateurs — distincte de gardePermission('indicateurs.consulter') :
 * un sectoriel invité sur un indicateur précis (InvitationIndicateur, § décision)
 * a un accès serveur réel sans détenir indicateurs.consulter en liste de
 * permissions ; sans cette garde dédiée, il serait redirigé avant même que
 * la page ne s'affiche (même classe de bug que le lien de nav Administration
 * déjà corrigé).
 */
export async function gardeAccesIndicateurs() {
  const profil = await queryClient.ensureQueryData(profilQueryOptions())
  if (profil !== null && !profil.aAccesIndicateurs) {
    throw redirect('/tableau-de-bord')
  }

  return null
}
