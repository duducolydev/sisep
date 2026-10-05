import { formaterTaux } from '@/lib/format'

interface BarreAtteinteProps {
  taux: number | null
}

/**
 * Taux d'atteinte d'un indicateur (§ 5.2) : la barre est bornée à 100 % à
 * l'affichage, mais le texte à côté montre la valeur réelle, y compris
 * au-delà de 100 % — jamais écrasée.
 */
export function BarreAtteinte({ taux }: BarreAtteinteProps) {
  if (taux === null) {
    return <span className="text-muted chiffres">—</span>
  }

  const largeur = Math.max(0, Math.min(100, taux * 100))
  const depasse = taux > 1

  return (
    <div className="flex items-center gap-2">
      <div className="h-2 w-24 overflow-hidden rounded-[var(--radius-barre)] bg-track">
        <div className={depasse ? 'h-full bg-ok' : 'h-full bg-accent'} style={{ width: `${largeur}%` }} />
      </div>
      <span className="chiffres text-sm">{formaterTaux(taux)}</span>
    </div>
  )
}
