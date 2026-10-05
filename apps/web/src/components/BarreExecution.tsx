import { formaterFcfa, formaterTaux } from '@/lib/format'

interface BarreExecutionProps {
  notifie: number | null
  engage: number | null
  depense: number | null
  /** Rythme attendu (0-1) à ce jour dans l'exercice — repère vertical optionnel (§ 9.4). */
  rythmeAttendu?: number | null
}

/** Barre superposée dépensé / engagé / disponible + repère de rythme — § 9.4 du brief. */
export function BarreExecution({ notifie, engage, depense, rythmeAttendu }: BarreExecutionProps) {
  if (notifie === null || notifie === 0) {
    return <span className="text-muted chiffres">—</span>
  }

  const partDepense = Math.min(100, ((depense ?? 0) / notifie) * 100)
  const partEngageNonDepense = Math.max(0, Math.min(100 - partDepense, (((engage ?? 0) - (depense ?? 0)) / notifie) * 100))
  const tauxExecution = notifie === 0 ? null : (depense ?? 0) / notifie

  return (
    <div className="flex flex-col gap-1">
      <div
        className="relative h-2 w-full overflow-hidden rounded-[var(--radius-barre)] bg-track"
        role="img"
        aria-label={`Exécuté ${formaterTaux(tauxExecution)} de ${formaterFcfa(notifie)}`}
      >
        <div className="absolute inset-y-0 left-0 bg-accent" style={{ width: `${partDepense}%` }} />
        <div
          className="absolute inset-y-0 bg-accent-clair"
          style={{ left: `${partDepense}%`, width: `${partEngageNonDepense}%` }}
        />
        {rythmeAttendu !== null && rythmeAttendu !== undefined && (
          <div className="absolute inset-y-0 w-px bg-bad" style={{ left: `${Math.min(100, rythmeAttendu * 100)}%` }} />
        )}
      </div>
      <div className="flex flex-wrap justify-between gap-x-2 text-xs text-muted">
        <span className="chiffres truncate">{formaterFcfa(depense ?? 0)}</span>
        <span className="chiffres shrink-0">{formaterTaux(tauxExecution)}</span>
      </div>
    </div>
  )
}
