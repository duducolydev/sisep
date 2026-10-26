import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { progressionComposante, type NoeudComposante } from '@/lib/composantes'
import { formaterTaux } from '@/lib/format'
import { hauteurGraphiqueEnBarres } from '@/lib/graphiques'

interface PhysiqueFinancierParComposanteProps {
  composantes: NoeudComposante[]
}

const HAUTEUR_LIGNE = 56

/**
 * La question du reporting : l'argent dépensé correspond-il au travail
 * fait ? Pour chaque composante, la progression des étapes (vert, moyenne
 * des activités suivies, comme « Progression des étapes » sur la page
 * Composantes) à côté de l'exécution financière (bleu, dépensé sur
 * notifié). Un taux non calculable reste absent, jamais 0.
 */
export function PhysiqueFinancierParComposante({ composantes }: PhysiqueFinancierParComposanteProps) {
  if (composantes.length === 0) {
    return <p className="text-sm text-muted">Aucune composante dans ce périmètre.</p>
  }

  const donnees = composantes.map((c) => ({
    code: c.code,
    libelle: c.libelle,
    physique: progressionComposante(c).progression,
    financier: c.notifie ? ((c.depense ?? 0) / c.notifie) * 100 : null,
  }))
  const maximum = Math.max(100, ...donnees.flatMap((d) => [d.physique ?? 0, d.financier ?? 0]))

  const etiquette = (props: unknown) => {
    const { x, y, width, height, value } = props as { x?: number; y?: number; width?: number; height?: number; value?: unknown }
    if (value === null || value === undefined) return null

    return (
      <text
        x={Number(x) + Number(width) + 6}
        y={Number(y) + Number(height) / 2}
        dominantBaseline="central"
        fill="var(--color-text)"
        fontSize={11}
        fontFamily="var(--font-chiffres)"
      >
        {formaterTaux(Number(value) / 100)}
      </text>
    )
  }

  return (
    <div>
      <div style={{ height: hauteurGraphiqueEnBarres(donnees.length, HAUTEUR_LIGNE) }}>
        <ResponsiveContainer width="100%" height="100%" minWidth={200}>
          <BarChart data={donnees} layout="vertical" margin={{ top: 4, right: 56, bottom: 0, left: 0 }} barGap={2} barCategoryGap="24%">
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" horizontal={false} />
            <XAxis
              type="number"
              domain={[0, Math.ceil(maximum / 25) * 25]}
              stroke="var(--color-muted)"
              fontSize={11}
              tick={{ fontFamily: 'var(--font-chiffres)' }}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => `${v} %`}
            />
            <YAxis type="category" dataKey="code" width={36} stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
            <Tooltip
              cursor={{ fill: 'var(--color-hover)' }}
              labelFormatter={(_l: unknown, charge: readonly { payload?: { code?: string; libelle?: string } }[]) => {
                const p = charge?.[0]?.payload

                return p ? `${p.code} — ${p.libelle}` : ''
              }}
              formatter={(valeur: unknown) => (valeur === null || valeur === undefined ? '—' : formaterTaux(Number(valeur) / 100))}
              contentStyle={{
                background: 'var(--color-surface)',
                border: '1px solid var(--color-border)',
                borderRadius: 8,
                fontFamily: 'var(--font-texte)',
                maxWidth: 360,
                whiteSpace: 'normal',
              }}
              itemStyle={{ fontFamily: 'var(--font-chiffres)' }}
            />
            <Bar dataKey="physique" name="Progression des étapes" fill="var(--color-success)" radius={[0, 4, 4, 0]} maxBarSize={14} isAnimationActive={false}>
              <LabelList dataKey="physique" content={etiquette} />
            </Bar>
            <Bar dataKey="financier" name="Exécution financière" fill="var(--color-accent)" radius={[0, 4, 4, 0]} maxBarSize={14} isAnimationActive={false}>
              <LabelList dataKey="financier" content={etiquette} />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-4 text-xs text-muted">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-success" aria-hidden="true" />
          Progression des étapes (physique)
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent" aria-hidden="true" />
          Exécution financière (dépensé / notifié)
        </span>
      </div>
    </div>
  )
}
