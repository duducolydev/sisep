import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts'

export interface LigneTauxExecution {
  libelle: string
  /** Fraction 0-1, ou null si aucune dotation notifiée (division par zéro — § 5.1). */
  tauxExecution: number | null
}

interface BarresTauxExecutionProps {
  donnees: LigneTauxExecution[]
}

/** Taux d'exécution (%) — barre empilée à 100 %, mêmes teintes que BarreExecution (accent = exécuté, piste = disponible). */
export function BarresTauxExecution({ donnees }: BarresTauxExecutionProps) {
  if (donnees.length === 0) {
    return null
  }

  const lignes = donnees.map((d) => {
    const execution = d.tauxExecution !== null ? Math.round(d.tauxExecution * 1000) / 10 : null
    const disponible = execution !== null ? Math.round((100 - execution) * 10) / 10 : null

    return { libelle: d.libelle, execution, disponible }
  })

  return (
    <div>
      <div className="h-64 w-full">
        <ResponsiveContainer width="100%" height="100%" minWidth={200} minHeight={180}>
          <BarChart data={lignes} margin={{ top: 24, right: 8, bottom: 0, left: 0 }} barCategoryGap="30%">
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="libelle" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
            <YAxis
              domain={[0, 100]}
              stroke="var(--color-muted)"
              fontSize={11}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => `${v} %`}
            />
            <Bar dataKey="execution" name="Exécution" stackId="taux" fill="var(--color-accent)" maxBarSize={64} isAnimationActive={false}>
              <LabelList
                dataKey="execution"
                position="center"
                formatter={(v: unknown) => (v === null || v === undefined ? '' : `${Number(v).toLocaleString('fr-FR')} %`)}
                fill="white"
                fontSize={11}
                fontWeight={600}
              />
            </Bar>
            <Bar
              dataKey="disponible"
              name="Disponible"
              stackId="taux"
              fill="var(--color-track)"
              radius={[3, 3, 0, 0]}
              maxBarSize={64}
              isAnimationActive={false}
            >
              <LabelList
                dataKey="disponible"
                position="center"
                formatter={(v: unknown) => (v === null || v === undefined ? '' : `${Number(v).toLocaleString('fr-FR')} %`)}
                fill="var(--color-text)"
                fontSize={11}
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
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-track" aria-hidden="true" />
          Disponible
        </span>
      </div>
    </div>
  )
}
