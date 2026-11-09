import { Bar, CartesianGrid, ComposedChart, LabelList, Line, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import { lignesTauxExecution } from '@/lib/graphiques'

export interface DonneeTauxExecution {
  libelle: string
  /** Fraction 0-1, ou null si aucune dotation notifiée (division par zéro — § 5.1). */
  tauxExecution: number | null
}

interface BarresTauxExecutionProps {
  donnees: DonneeTauxExecution[]
}

/**
 * Taux d'exécution (%) — barre empilée à 100 %, mêmes teintes que BarreExecution
 * (accent = exécuté, piste = reste à dépenser).
 *
 * L'étiquette passe par une ligne invisible posée au sommet de la barre : Recharts
 * ne dessine ni barre ni étiquette pour une part à 0, or il faut justement pouvoir
 * lire « 0 % exécuté » et « 100 % exécuté ».
 */
export function BarresTauxExecution({ donnees }: BarresTauxExecutionProps) {
  if (donnees.length === 0) {
    return null
  }

  return (
    <div>
      <div className="h-64 w-full">
        <ResponsiveContainer width="100%" height="100%" minWidth={200} minHeight={180}>
          <ComposedChart data={lignesTauxExecution(donnees)} margin={{ top: 24, right: 8, bottom: 0, left: 0 }} barCategoryGap="30%">
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="libelle" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
            <YAxis
              domain={[0, 100]}
              stroke="var(--color-muted)"
              fontSize={11}
              tick={{ fontFamily: 'var(--font-chiffres)' }}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => `${v} %`}
            />
            <Bar dataKey="execution" name="Exécuté" stackId="taux" fill="var(--color-accent)" maxBarSize={64} isAnimationActive={false} />
            <Bar
              dataKey="resteADepenser"
              name="Reste à dépenser"
              stackId="taux"
              fill="var(--color-track)"
              radius={[3, 3, 0, 0]}
              maxBarSize={64}
              isAnimationActive={false}
            />
            {/* Rayon nul et non dot={false} : Recharts dessine toujours le point d'une ligne à un seul exercice. */}
            <Line dataKey="sommet" stroke="none" dot={{ r: 0, strokeWidth: 0 }} activeDot={false} isAnimationActive={false} legendType="none">
              <LabelList
                dataKey="etiquette"
                position="top"
                fill="var(--color-text)"
                fontSize={11}
                fontWeight={700}
                fontFamily="var(--font-chiffres)"
              />
            </Line>
          </ComposedChart>
        </ResponsiveContainer>
      </div>
      <div className="mt-1 flex items-center gap-4 text-xs text-muted">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent" aria-hidden="true" />
          Exécuté
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-track" aria-hidden="true" />
          Reste à dépenser
        </span>
      </div>
    </div>
  )
}
