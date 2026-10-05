interface SqueletteProps {
  lignes?: number
  className?: string
}

/** Remplace un « Chargement… » brut par des barres pulsantes — aucune nouvelle couleur (réutilise --color-track). */
export function Squelette({ lignes = 3, className = '' }: SqueletteProps) {
  return (
    <div role="status" aria-label="Chargement…" className={`flex flex-col gap-2 ${className}`}>
      {Array.from({ length: lignes }).map((_, i) => (
        <div key={i} className="h-4 animate-pulse rounded-[var(--radius-controle)] bg-track" style={{ width: `${100 - i * 12}%` }} />
      ))}
    </div>
  )
}
