import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formaterFcfa, formaterMds } from '@/lib/format'

export interface LigneMontantExecution {
  libelle: string
  execution: number
  objectif: number
}

interface BarresMontantsExecutionProps {
  donnees: LigneMontantExecution[]
}

const FORMATEUR_MDS_AXE = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })

/** Montants (FCFA) exécution vs objectif — barres groupées, mêmes teintes que BarreExecution (accent/accent-clair). */
export function BarresMontantsExecution({ donnees }: BarresMontantsExecutionProps) {
  if (donnees.length === 0) {
    return null
  }

  // Dès deux barres dessinées, des montants entiers se chevauchent de l'une à
  // l'autre : l'étiquette passe en milliards et le survol garde le montant exact.
  const barresDessinees = donnees.flatMap((d) => [d.execution, d.objectif]).filter((v) => v > 0).length
  const etiquette = (v: unknown) => (barresDessinees > 1 ? `${formaterMds(Number(v))} Md` : formaterFcfa(Number(v)))

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
              tick={{ fontFamily: 'var(--font-chiffres)' }}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => FORMATEUR_MDS_AXE.format(v / 1_000_000_000)}
            />
            <Tooltip
              cursor={{ fill: 'var(--color-hover)' }}
              formatter={(valeur: unknown) => formaterFcfa(Number(valeur))}
              contentStyle={{
                background: 'var(--color-surface)',
                border: '1px solid var(--color-border)',
                borderRadius: 8,
                fontFamily: 'var(--font-texte)',
              }}
              itemStyle={{ fontFamily: 'var(--font-chiffres)' }}
            />
            <Bar dataKey="execution" name="Exécution" fill="var(--color-accent)" radius={[3, 3, 0, 0]} maxBarSize={56} isAnimationActive={false}>
              <LabelList
                dataKey="execution"
                position="top"
                formatter={etiquette}
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
                formatter={etiquette}
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
