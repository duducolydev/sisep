import { CartesianGrid, Line, LineChart, ReferenceDot, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formaterDate, formaterValeurIndicateur } from '@/lib/format'

interface PointIndicateur {
  periode: string
  valeur: number
}

interface CourbeIndicateurProps {
  points: PointIndicateur[]
  unite: string
  /** Taux d'atteinte (0-1) — colore la courbe comme sur la maquette (bon/vigilance/critique). */
  tauxAtteinte?: number | null
}

/**
 * Couleur par seuil d'atteinte, comme la maquette (§ 9.4) : ≥60 % tenu,
 * ≥45 % vigilance, sinon critique.
 *
 * Deux substitutions mesurées : --color-ok vaut --color-accent, donc un
 * point ≥60 % était de la même couleur qu'un point sans taux connu ; et
 * --color-warn ne se distingue pas de --color-bad en vision normale
 * (ΔE 9,9), le même écueil que tokens.css documente pour les statuts du
 * module Projets, résolu de la même façon par --color-eyebrow.
 */
function couleurAtteinte(tauxAtteinte: number | null | undefined): string {
  if (tauxAtteinte === null || tauxAtteinte === undefined) return 'var(--color-accent)'
  const pourcent = tauxAtteinte * 100
  if (pourcent >= 60) return 'var(--color-success)'
  if (pourcent >= 45) return 'var(--color-eyebrow)'

  return 'var(--color-bad)'
}

/** Courbe d'évolution d'un indicateur — § 2.4, § 9.4 du brief. */
export function CourbeIndicateur({ points, unite, tauxAtteinte }: CourbeIndicateurProps) {
  if (points.length === 0) {
    return <p className="text-muted">Aucune valeur validée pour le moment.</p>
  }

  const couleur = couleurAtteinte(tauxAtteinte)
  const dernierPoint = points[points.length - 1]

  return (
    <div className="h-56 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={points} margin={{ top: 16, right: 50, bottom: 0, left: 0 }}>
          <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
          <XAxis
            dataKey="periode"
            stroke="var(--color-muted)"
            fontSize={12}
            tickLine={false}
            axisLine={false}
            tickFormatter={(v: string) => formaterDate(v)}
          />
          <YAxis stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
          <Tooltip
            formatter={(valeur: unknown) => `${formaterValeurIndicateur(Number(valeur))} ${unite}`}
            labelFormatter={(v: unknown) => (typeof v === 'string' ? formaterDate(v) : '')}
            contentStyle={{
              background: 'var(--color-surface)',
              border: '1px solid var(--color-border)',
              borderRadius: 8,
              fontFamily: 'var(--font-texte)',
            }}
          />
          <Line type="monotone" dataKey="valeur" name="Valeur" stroke={couleur} strokeWidth={2.5} strokeLinecap="round" dot={false} />
          <ReferenceDot
            x={dernierPoint.periode}
            y={dernierPoint.valeur}
            r={4.5}
            fill={couleur}
            stroke="none"
            label={{
              value: `${formaterValeurIndicateur(dernierPoint.valeur)} ${unite}`,
              position: 'top',
              fill: couleur,
              fontSize: 11,
              fontWeight: 700,
            }}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}
