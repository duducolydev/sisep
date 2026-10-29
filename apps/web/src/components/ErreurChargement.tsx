import { ApiError } from '@/lib/api-client'

/**
 * Échec du chargement d'une liste. Un refus du serveur (403) n'est pas une
 * panne : « Réessayez » y serait trompeur, réessayer donnant le même refus.
 * Les gardes de route l'évitent d'ordinaire ; il reste possible quand les
 * rôles d'un compte changent pendant sa session.
 *
 * `quoi` complète « consulter » et « charger » : « les comptes », « le journal ».
 */
export function ErreurChargement({ erreur, quoi }: { erreur: unknown; quoi: string }) {
  if (erreur instanceof ApiError && erreur.status === 403) {
    return (
      <p className="text-bad">
        Votre rôle ne permet pas de consulter {quoi}. Si vous en avez besoin, demandez à un administrateur de vérifier
        vos rôles.
      </p>
    )
  }

  return <p className="text-bad">Impossible de charger {quoi}. Réessayez.</p>
}
