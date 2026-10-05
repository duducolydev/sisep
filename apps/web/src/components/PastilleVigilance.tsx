const LIBELLES: Record<string, string> = {
  en_retard: 'En retard',
  risque_eleve: 'Risque élevé',
}

const CLASSES: Record<string, string> = {
  en_retard: 'text-warn bg-warn-bg',
  risque_eleve: 'text-bad bg-bad-bg',
}

/** Vigilance calculée (jamais stockée, § 5.3 du brief) — affichée en plus du statut, jamais à sa place. */
export function PastilleVigilance({ vigilance, className = '' }: { vigilance: string | null; className?: string }) {
  if (null === vigilance) return null

  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${CLASSES[vigilance] ?? 'text-muted bg-nested'} ${className}`}>
      {LIBELLES[vigilance] ?? vigilance}
    </span>
  )
}
