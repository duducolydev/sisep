import type { ReactNode } from 'react'
import * as Dialog from '@radix-ui/react-dialog'

interface ModaleProps {
  titre: string
  onFermer: () => void
  /** `large` pour un formulaire à plusieurs colonnes : plus large, donc moins haut que l'écran. */
  taille?: 'normale' | 'large'
  children: ReactNode
}

/** Dialogue de saisie — § 9.4 du brief. Primitive Radix sans style, habillée avec les tokens. */
export function Modale({ titre, onFermer, taille = 'normale', children }: ModaleProps) {
  return (
    <Dialog.Root open onOpenChange={(ouvert) => !ouvert && onFermer()}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-[rgba(8,10,16,0.5)]" />
        <Dialog.Content
          className={`fixed top-1/2 left-1/2 max-h-[90vh] w-[calc(100vw-2rem)] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-[var(--radius-carte)] bg-surface p-6 shadow-[var(--shadow-modal)] ${
            taille === 'large' ? 'max-w-3xl' : 'max-w-lg'
          }`}
        >
          <div className="mb-4 flex items-center justify-between">
            <Dialog.Title className="font-titre text-lg">{titre}</Dialog.Title>
            <Dialog.Close aria-label="Fermer" className="text-muted">
              &times;
            </Dialog.Close>
          </div>
          {children}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}
