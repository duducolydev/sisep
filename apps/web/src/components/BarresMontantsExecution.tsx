import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import { formaterFcfa } from '@/lib/format'

export interface LigneMontantExecution {
  libelle: string
  execution: number
  objectif: number
}

interface BarresMontantsExecutionProps {
  donnees: LigneMontantExecution[]
}

/** Montants (FCFA) exécution vs objectif — barres groupées, mêmes teintes que BarreExecution (accent/accent-clair). */
export function BarresMontantsExecution({ donnees }: BarresMontantsExecutionProps) {
  if (donnees.length === 0) {
    return null
  }

  return (
    <div>
      <div className="h-64 w-full">
        <ResponsiveContainer width="100%" height="100%" minWidth={200} minHeight={180}>
          <BarChart data={donnees} margin={{ top: 24, right: 8, bottom: 0, left: 0 }} barGap={4}>
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="libelle" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
            <YAxis
              stroke="var(--color-muted)"
              fontSize={11}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => Math.round(v / 1_000_000_000).toString()}
            />
            <Bar dataKey="execution" name="Exécution" fill="var(--color-accent)" radius={[3, 3, 0, 0]} maxBarSize={56} isAnimationActive={false}>
              <LabelList
                dataKey="execution"
                position="top"
                formatter={(v: unknown) => formaterFcfa(Number(v))}
                fill="var(--color-text)"
                fontSize={10}
                fontWeight={600}
              />
            </Bar>
            <Bar
              dataKey="objectif"
              name="Objectif"
              fill="var(--color-accent-clair)"
              radius={[3, 3, 0, 0]}
              maxBarSize={56}
              isAnimationActive={false}
            >
              <LabelList
                dataKey="objectif"
                position="top"
                formatter={(v: unknown) => formaterFcfa(Number(v))}
                fill="var(--color-text)"
                fontSize={10}
                fontWeight={600}
              />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
      <div className="mt-1 flex items-center gap-4 text-xs text-muted">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent" aria-hidden="true" />
          Exécution
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent-clair" aria-hidden="true" />
          Objectif
        </span>
      </div>
    </div>
  )
}
