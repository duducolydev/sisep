import { useQuery } from '@tanstack/react-query'
import { profilQueryOptions } from './auth'

export function useUtilisateurCourant() {
  return useQuery(profilQueryOptions())
}

/**
 * Sert uniquement à adapter l'affichage (masquer un bouton, un lien) — le
 * contrôle qui compte est toujours fait côté serveur par le PermissionVoter
 * (§ 7.2 et § 8 du brief).
 */
export function usePeut(code: string): boolean {
  const { data } = useUtilisateurCourant()

  return data?.permissions.includes(code) ?? false
}

/**
 * Requalifier une pièce jointe (intitulé, type) : son déposant, ou l'UGP
 * (`documents.gerer` sur tout le projet) — même règle que
 * PieceJointeController::modifier.
 */
export function usePeutRequalifierPiece(): (deposeParUuid: string) => boolean {
  const { data } = useUtilisateurCourant()
  const ugp = data?.permissionsPortee['documents.gerer'] === null

  return (deposeParUuid) => ugp || data?.uuid === deposeParUuid
}

/**
 * Variante scopée par structure (§ 7.2) : un point focal DSID ne doit pas
 * voir un bouton d'édition sur une activité de la DPEE, même s'il détient le
 * code ailleurs. `structureUuid` nul (entité sans structure responsable)
 * n'autorise que les attributions globales — même règle que
 * PermissionsEffectives::estAutorisePour côté serveur. Hint d'affichage
 * uniquement, revérifié par le Voter à l'écriture.
 */
export function usePeutSur(code: string, structureUuid: string | null | undefined): boolean {
  const { data } = useUtilisateurCourant()
  const portee = data?.permissionsPortee[code]

  if (portee === undefined) return false
  if (portee === null) return true

  return structureUuid != null && portee.includes(structureUuid)
}
