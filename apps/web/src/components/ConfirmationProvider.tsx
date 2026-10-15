import { useCallback, useRef, useState, type ReactNode } from 'react'
import { ConfirmationContext, type DemandeConfirmation } from '@/lib/confirmation'
import { Modale } from './Modale'
import { Bouton } from './Bouton'

/**
 * Fenêtre de confirmation unique, partagée par toutes les actions
 * destructives (suppression d'un compte, retrait d'un document, d'un agent,
 * d'une étape…). Un clic sur une corbeille ne doit jamais suffire à faire
 * disparaître une donnée : l'icône voisine est souvent « Modifier ».
 */
export function ConfirmationProvider({ children }: { children: ReactNode }) {
  const [demande, setDemande] = useState<DemandeConfirmation | null>(null)
  const resoudre = useRef<((confirme: boolean) => void) | null>(null)

  const confirmer = useCallback(
    (nouvelle: DemandeConfirmation) =>
      new Promise<boolean>((resolve) => {
        // Une demande encore ouverte est abandonnée, jamais confirmée.
        resoudre.current?.(false)
        resoudre.current = resolve
        setDemande(nouvelle)
      }),
    [],
  )

  function repondre(confirme: boolean) {
    resoudre.current?.(confirme)
    resoudre.current = null
    setDemande(null)
  }

  return (
    <ConfirmationContext.Provider value={confirmer}>
      {children}
      {demande && (
        <Modale titre={demande.titre} onFermer={() => repondre(false)}>
          <p className="text-muted">{demande.message}</p>
          <div className="mt-6 flex justify-end gap-3 border-t border-border pt-4">
            <Bouton variante="secondaire" onClick={() => repondre(false)}>
              Annuler
            </Bouton>
            {/* Bouton plein : la variante « danger » de Bouton n'est qu'un texte, trop discret pour l'action finale. */}
            <button
              type="button"
              onClick={() => repondre(true)}
              className="rounded-[var(--radius-controle)] bg-bad px-4 py-2 font-semibold text-white transition hover:brightness-110"
            >
              {demande.action}
            </button>
          </div>
        </Modale>
      )}
    </ConfirmationContext.Provider>
  )
}
