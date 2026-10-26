import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { PointDecaissement } from '@/lib/budget'
import { formaterFcfa, formaterMds } from '@/lib/format'

interface BarresDecaissementProps {
  points: PointDecaissement[]
}

const FORMATEUR_MOIS = new Intl.DateTimeFormat('fr-FR', { month: 'long', timeZone: 'Africa/Dakar' })
const FORMATEUR_MOIS_COURT = new Intl.DateTimeFormat('fr-FR', { month: 'short', timeZone: 'Africa/Dakar' })
const FORMATEUR_MDS_AXE = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })

/**
 * Décaissement cumulé mois par mois, en barres (demande du PHASAOC, octobre
 * 2026) : le réalisé à côté de la trajectoire prévue, pour lire l'écart d'un
 * coup d'œil — § 2.1, § 2.3, § 9.4 du brief. Le réalisé s'arrête au mois
 * courant : pas de barre pour une dépense qui n'a pas encore eu lieu.
 */
export function BarresDecaissement({ points }: BarresDecaissementProps) {
  if (points.length === 0) {
    return <p className="text-muted">Pas encore de données de décaissement pour cet exercice.</p>
  }

  const moisActuel = new Date().toISOString().slice(0, 7)
  const donnees = points.map((p) => ({
    ...p,
    libelle: FORMATEUR_MOIS_COURT.format(new Date(`${p.mois}-01T12:00:00Z`)),
    realiseAffiche: p.mois <= moisActuel ? p.realise : null,
  }))
  const dernier = [...donnees].reverse().find((p) => p.realiseAffiche !== null) ?? null
  const ecart = dernier ? (dernier.realiseAffiche ?? 0) - dernier.prevu : null
  // Recharts ne numérote que les barres dessinées : un mois à 0 n'en a pas.
  // La dernière barre du réalisé est donc la n-ième non nulle, pas le n-ième mois.
  const indexDerniereBarre = donnees.filter((p) => (p.realiseAffiche ?? 0) > 0).length - 1

  return (
    <div>
      <div className="h-64 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={donnees} margin={{ top: 20, right: 8, bottom: 0, left: 0 }} barGap={2} barCategoryGap="22%">
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="libelle" stroke="var(--color-muted)" fontSize={11} tickLine={false} axisLine={false} interval={0} />
            <YAxis
              width="auto"
              stroke="var(--color-muted)"
              fontSize={11}
              tick={{ fontFamily: 'var(--font-chiffres)' }}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => FORMATEUR_MDS_AXE.format(v / 1_000_000_000)}
            />
            <Tooltip
              cursor={{ fill: 'var(--color-hover)' }}
              labelFormatter={(_l: unknown, charge: readonly { payload?: { mois?: string } }[]) => {
                const mois = charge?.[0]?.payload?.mois

                return mois ? FORMATEUR_MOIS.format(new Date(`${mois}-01T12:00:00Z`)) : ''
              }}
              formatter={(valeur: unknown) => formaterFcfa(Number(valeur))}
              contentStyle={{
                background: 'var(--color-surface)',
                border: '1px solid var(--color-border)',
                borderRadius: 8,
                fontFamily: 'var(--font-texte)',
              }}
              itemStyle={{ fontFamily: 'var(--font-chiffres)' }}
            />
            <Bar dataKey="realiseAffiche" name="Réalisé" fill="var(--color-accent)" radius={[4, 4, 0, 0]} maxBarSize={22} isAnimationActive={false}>
              {/* Seul le dernier mois réalisé est chiffré : le survol donne les autres. */}
              <LabelList
                dataKey="realiseAffiche"
                content={(props) => {
                  const { x, y, width, value, index } = props as { x?: number | string; y?: number | string; width?: number | string; value?: unknown; index?: number }
                  if (index !== indexDerniereBarre || value === null || value === undefined) return null

                  return (
                    <text
                      x={Number(x) + Number(width) / 2}
                      y={Number(y) - 6}
                      textAnchor="middle"
                      fill="var(--color-text)"
                      fontSize={11}
                      fontWeight={700}
                      fontFamily="var(--font-chiffres)"
                    >
                      {formaterMds(Number(value))} Md
                    </text>
                  )
                }}
              />
            </Bar>
            <Bar dataKey="prevu" name="Trajectoire prévue" fill="var(--color-accent-clair)" fillOpacity={0.55} radius={[4, 4, 0, 0]} maxBarSize={22} isAnimationActive={false} />
          </BarChart>
        </ResponsiveContainer>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-4 text-xs text-muted">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent" aria-hidden="true" />
          Réalisé (cumulé)
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent-clair opacity-55" aria-hidden="true" />
          Trajectoire prévue (cumulée)
        </span>
        <span className="text-muted">Mds FCFA</span>
        {dernier && ecart !== null && (
          <span className="ml-auto">
            Écart fin {FORMATEUR_MOIS.format(new Date(`${dernier.mois}-01T12:00:00Z`))} :{' '}
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
