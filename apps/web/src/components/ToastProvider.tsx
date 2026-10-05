import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react'

const ToastContext = createContext<(message: string) => void>(() => {})

/** Retour visuel transitoire après une écriture réussie — § maquette .toast/.toast.on. */
export function useToast() {
  return useContext(ToastContext)
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [message, setMessage] = useState('')
  const [visible, setVisible] = useState(false)
  const minuteur = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  const afficherToast = useCallback((texte: string) => {
    clearTimeout(minuteur.current)
    setMessage(texte)
    requestAnimationFrame(() => setVisible(true))
    minuteur.current = setTimeout(() => setVisible(false), 2600)
  }, [])

  return (
    <ToastContext.Provider value={afficherToast}>
      {children}
      <div
        role="status"
        aria-live="polite"
        className={`pointer-events-none fixed bottom-[22px] left-1/2 z-[200] -translate-x-1/2 rounded-full bg-text px-[18px] py-[11px] text-[13px] text-bg transition duration-[250ms] ${
          visible ? 'translate-y-0 opacity-100' : 'translate-y-5 opacity-0'
        }`}
      >
        {message}
      </div>
    </ToastContext.Provider>
  )
}
