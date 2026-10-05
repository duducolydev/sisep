import type { ReactNode } from 'react'

/** Icône parlante avec libellé accessible (titre + aria-label) — jamais une icône seule sans texte perceptible. */
export function IconeBouton({
  libelle,
  onClick,
  disabled,
  classeCouleur = 'text-muted hover:bg-accent-bg hover:text-accent',
  children,
}: {
  libelle: string
  onClick: () => void
  disabled?: boolean
  classeCouleur?: string
  children: ReactNode
}) {
  return (
    <button
      type="button"
      title={libelle}
      aria-label={libelle}
      disabled={disabled}
      onClick={onClick}
      className={`inline-flex shrink-0 items-center justify-center rounded-[var(--radius-controle)] p-1.5 transition-colors disabled:opacity-50 ${classeCouleur}`}
    >
      {children}
    </button>
  )
}

/** Même rendu que IconeBouton mais pour un lien (export, téléchargement…). */
export function IconeLien({
  libelle,
  href,
  classeCouleur = 'text-muted hover:bg-accent-bg hover:text-accent',
  children,
}: {
  libelle: string
  href: string
  classeCouleur?: string
  children: ReactNode
}) {
  return (
    <a
      href={href}
      title={libelle}
      aria-label={libelle}
      className={`inline-flex shrink-0 items-center justify-center rounded-[var(--radius-controle)] p-1.5 transition-colors ${classeCouleur}`}
    >
      {children}
    </a>
  )
}

const iconeProps = {
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.7,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  className: 'h-4 w-4',
}

export function IconeModifier() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z" />
    </svg>
  )
}

export function IconeSupprimer() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M3 6h18" />
      <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
      <path d="M10 11v6" />
      <path d="M14 11v6" />
    </svg>
  )
}

export function IconeMettreAJour() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M21 12a9 9 0 1 1-2.64-6.36" />
      <path d="M21 3v6h-6" />
    </svg>
  )
}

export function IconeExporter() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M12 15V3" />
      <path d="M7 8l5-5 5 5" />
      <path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4" />
    </svg>
  )
}

export function IconeVoir() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M5 12h14" />
      <path d="M13 6l6 6-6 6" />
    </svg>
  )
}

export function IconeDeplierTout() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M7 8l5-5 5 5" />
      <path d="M7 13l5 5 5-5" />
    </svg>
  )
}

export function IconeReplierTout() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M7 4l5 5 5-5" />
      <path d="M7 16l5-5 5 5" />
    </svg>
  )
}

export function IconeValider() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  )
}

export function IconeAnnuler() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M18 6 6 18" />
      <path d="M6 6l12 12" />
    </svg>
  )
}

export function IconePlus() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M12 5v14" />
      <path d="M5 12h14" />
    </svg>
  )
}

export function IconeCalendrier() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <rect x="3" y="5" width="18" height="16" rx="2" />
      <path d="M3 10h18" />
      <path d="M8 3v4" />
      <path d="M16 3v4" />
    </svg>
  )
}

export function IconeRecherche() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4.3-4.3" />
    </svg>
  )
}

export function IconeTrombone() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M21.44 11.05l-9.19 9.19a5.5 5.5 0 0 1-7.78-7.78l9.19-9.19a3.5 3.5 0 0 1 4.95 4.95l-9.19 9.19a1.5 1.5 0 0 1-2.12-2.12l8.48-8.48" />
    </svg>
  )
}

export function IconeTelecharger() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M12 3v12" />
      <path d="M7 10l5 5 5-5" />
      <path d="M4 20h16" />
    </svg>
  )
}

export function IconeComposante() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <rect x="3" y="3" width="8" height="8" rx="1.5" />
      <rect x="13" y="3" width="8" height="8" rx="1.5" />
      <rect x="3" y="13" width="8" height="8" rx="1.5" />
      <rect x="13" y="13" width="8" height="8" rx="1.5" />
    </svg>
  )
}

export function IconeStructure() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <rect x="4" y="3" width="16" height="18" rx="1" />
      <path d="M9 8h1M14 8h1M9 12h1M14 12h1M9 16h1M14 16h1" />
      <path d="M9 21v-4h6v4" />
    </svg>
  )
}

export function IconeStatut() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M5 3v18" />
      <path d="M5 4h13l-3 4 3 4H5" />
    </svg>
  )
}

export function IconeVueCartes() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <rect x="3" y="3" width="8" height="8" rx="1.5" />
      <rect x="13" y="3" width="8" height="8" rx="1.5" />
      <rect x="3" y="13" width="8" height="8" rx="1.5" />
      <rect x="13" y="13" width="8" height="8" rx="1.5" />
    </svg>
  )
}

export function IconeVueTableau() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <rect x="3" y="4" width="18" height="16" rx="1.5" />
      <path d="M3 10h18" />
      <path d="M9 4v16" />
    </svg>
  )
}

export function IconeVueKanban() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <rect x="3" y="4" width="18" height="16" rx="1.5" />
      <path d="M9 4v16" />
      <path d="M15 4v10" />
    </svg>
  )
}

export function IconeInviter() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <circle cx="9" cy="8" r="3.2" />
      <path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5" />
      <path d="M18 8v6M15 11h6" />
    </svg>
  )
}

export function IconeLier() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.2 1.1" />
      <path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.2-1.1" />
    </svg>
  )
}

export function IconeTransmettre() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M22 2 11 13" />
      <path d="M22 2 15 22l-4-9-9-4Z" />
    </svg>
  )
}

export function IconeDotation() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <rect x="2" y="6" width="20" height="12" rx="2" />
      <circle cx="12" cy="12" r="2.5" />
      <path d="M6 12h.01M18 12h.01" />
    </svg>
  )
}

export function IconeEngagement() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
      <path d="M14 2v6h6" />
      <path d="M8 15h7" />
    </svg>
  )
}

export function IconeCopier() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <rect x="9" y="9" width="12" height="12" rx="2" />
      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
    </svg>
  )
}

export function IconeDepense() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <circle cx="9" cy="9" r="6" />
      <path d="M18.1 10.4A6 6 0 1 1 10.4 18.1" />
    </svg>
  )
}
