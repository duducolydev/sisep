import { CartesianGrid, Line, LineChart, ReferenceDot, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { PointDecaissement } from '@/lib/budget'
import { formaterFcfa, formaterMds } from '@/lib/format'

interface CourbeDecaissementProps {
  points: PointDecaissement[]
}

const FORMATEUR_MOIS = new Intl.DateTimeFormat('fr-FR', { month: 'long', timeZone: 'Africa/Dakar' })

/** Décaissement cumulé réalisé comparé à la trajectoire attendue — § 2.1, § 2.3, § 9.4 du brief. */
export function CourbeDecaissement({ points }: CourbeDecaissementProps) {
  if (points.length === 0) {
    return <p className="text-muted">Pas encore de données de décaissement pour cet exercice.</p>
  }

  // Le réalisé est cumulé et reste plat au-delà du mois courant (pas de
  // dépense future) — masqué en `null` pour que la courbe s'arrête
  // franchement au dernier mois connu, comme sur la maquette, plutôt que de
  // tracer un plateau jusqu'à décembre.
  const moisActuel = new Date().toISOString().slice(0, 7)
  const donnees = points.map((p) => ({ ...p, realiseAffiche: p.mois <= moisActuel ? p.realise : null }))
  const dernierPointRealise = [...donnees].reverse().find((p) => p.realiseAffiche !== null) ?? null
  const ecart = dernierPointRealise ? (dernierPointRealise.realiseAffiche ?? 0) - dernierPointRealise.prevu : null

  return (
    <div>
      <div className="h-64 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={donnees} margin={{ top: 16, right: 60, bottom: 0, left: 0 }}>
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="mois" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
            <YAxis
              stroke="var(--color-muted)"
              fontSize={12}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => Math.round(v / 1_000_000_000).toString()}
            />
            <Tooltip
              formatter={(valeur: unknown) => formaterFcfa(Number(valeur))}
              contentStyle={{
                background: 'var(--color-surface)',
                border: '1px solid var(--color-border)',
                borderRadius: 8,
                fontFamily: 'var(--font-texte)',
              }}
            />
            <Line
              type="monotone"
              dataKey="prevu"
              name="Trajectoire prévue"
              stroke="var(--color-bad)"
              strokeDasharray="5 5"
              strokeOpacity={0.85}
              dot={false}
              strokeWidth={1.5}
            />
            <Line
              type="monotone"
              dataKey="realiseAffiche"
              name="Réalisé"
              stroke="var(--color-accent)"
              dot={false}
              strokeWidth={2.5}
              strokeLinecap="round"
            />
            {dernierPointRealise && (
              <ReferenceDot
                x={dernierPointRealise.mois}
                y={dernierPointRealise.realiseAffiche ?? 0}
                r={4.5}
                fill="var(--color-accent)"
                stroke="none"
                label={{
                  value: `${formaterMds(dernierPointRealise.realiseAffiche ?? 0)} Mds`,
                  position: 'top',
                  fill: 'var(--color-accent)',
                  fontSize: 11,
                  fontWeight: 700,
                }}
              />
            )}
          </LineChart>
        </ResponsiveContainer>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-4 text-xs text-muted">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent" aria-hidden="true" />
          Réalisé
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-bad" aria-hidden="true" />
          Trajectoire prévue
        </span>
        {dernierPointRealise && ecart !== null && (
          <span className="ml-auto">
            Écart au {FORMATEUR_MOIS.format(new Date(`${dernierPointRealise.mois}-01`))} :{' '}
            <span className={`chiffres font-semibold ${ecart < 0 ? 'text-bad' : 'text-ok'}`}>
              {ecart < 0 ? '−' : '+'}
              {formaterMds(Math.abs(ecart))} Md
            </span>
          </span>
        )}
      </div>
    </div>
  )
}
