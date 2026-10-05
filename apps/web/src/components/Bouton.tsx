import type { ReactNode } from 'react'

type VarianteBouton = 'principal' | 'secondaire' | 'discret' | 'danger'
type TailleBouton = 'normale' | 'compacte' | 'mini'

interface BoutonProps {
  children: ReactNode
  onClick?: () => void
  type?: 'button' | 'submit'
  variante?: VarianteBouton
  taille?: TailleBouton
  /** Icône parlante placée avant le libellé — jamais seule (le libellé reste toujours visible). */
  icone?: ReactNode
  disabled?: boolean
  title?: string
  className?: string
}

const VARIANTES: Record<VarianteBouton, string> = {
  principal: 'bg-accent text-white hover:bg-accent-d',
  secondaire: 'border border-border bg-surface text-text hover:border-accent hover:text-accent',
  discret: 'text-accent hover:bg-accent-bg',
  danger: 'text-bad hover:bg-bad-bg',
}

const TAILLES: Record<TailleBouton, string> = {
  normale: 'px-4 py-2',
  compacte: 'px-3 py-1.5 text-sm',
  mini: 'px-2 py-1 text-xs',
}

/**
 * Bouton d'action de l'application — reprend à l'identique les classes déjà
 * employées page par page (accent plein pour l'action principale, contour
 * pour la secondaire), avec un état de survol réel sur toutes les variantes :
 * une action ne doit jamais se présenter comme un simple texte coloré.
 *
 * Les variantes « discret » et « danger » remplacent les anciens boutons
 * texte nu des listes : même légèreté visuelle, mais avec surface cliquable,
 * arrondi et retour au survol.
 */
export function Bouton({
  children,
  onClick,
  type = 'button',
  variante = 'principal',
  taille = 'compacte',
  icone,
  disabled,
  title,
  className = '',
}: BoutonProps) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`inline-flex shrink-0 items-center gap-1.5 rounded-[var(--radius-controle)] font-semibold transition-colors disabled:opacity-60 ${VARIANTES[variante]} ${TAILLES[taille]} ${className}`}
    >
      {icone}
      {children}
    </button>
  )
}
