import { createContext, useContext } from 'react'

export interface DemandeConfirmation {
  titre: string
  message: string
  /** Libellé du bouton qui exécute l'action, à l'infinitif : « Supprimer », « Retirer ». */
  action: string
}

export const ConfirmationContext = createContext<(demande: DemandeConfirmation) => Promise<boolean>>(() =>
  Promise.resolve(false),
)

/**
 * Demande une confirmation avant une action destructive. Résout `true` si
 * l'utilisateur confirme, `false` s'il annule ou ferme la fenêtre.
 *
 * Remplace `window.confirm`, que rien dans l'application n'utilise : une
 * fenêtre native ne reprend ni la charte ni la langue de l'interface.
 */
export function useConfirmer() {
  return useContext(ConfirmationContext)
}
