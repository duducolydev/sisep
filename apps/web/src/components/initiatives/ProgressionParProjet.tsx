import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { Initiative } from '@/lib/initiatives'
import { formaterTaux } from '@/lib/format'
import { hauteurGraphiqueEnBarres } from '@/lib/graphiques'

interface ProgressionParProjetProps {
  projets: Initiative[]
}

interface LigneProjet {
  uuid: string
  libelle: string
  taux: number
}

const HAUTEUR_LIGNE = 28
const MAX_LIGNES = 12

/** Une barre par projet — moyenne de l'avancement de ses activités. Magnitude sans dimension catégorielle propre au projet (le statut n'existe qu'au niveau activité) : une seule teinte, pas de légende (§ skill dataviz — une série unique se nomme par le titre). */
export function ProgressionParProjet({ projets }: ProgressionParProjetProps) {
  const toutes: LigneProjet[] = projets
    .map((i) => {
      const avancements = i.activites.map((a) => a.avancement).filter((a): a is number => a !== null)
      const taux = avancements.length > 0 ? (avancements.reduce((s, a) => s + a, 0) / avancements.length) * 100 : null

      return null === taux ? null : { uuid: i.uuid, libelle: i.libelle, taux }
    })
    .filter((l): l is LigneProjet => l !== null)
    .sort((a, b) => b.taux - a.taux)

  const lignes = toutes.slice(0, MAX_LIGNES)
  const nombreExclus = projets.length - lignes.length

  if (lignes.length === 0) {
    return <p className="text-sm text-muted">Aucun projet avec une activité pour le moment.</p>
  }

  return (
    <div>
      <div style={{ height: hauteurGraphiqueEnBarres(lignes.length, HAUTEUR_LIGNE) }}>
        <ResponsiveContainer width="100%" height="100%" minWidth={200}>
          <BarChart data={lignes} layout="vertical" margin={{ top: 8, right: 44, bottom: 0, left: 8 }} barCategoryGap={6}>
            <XAxis
              type="number"
              domain={[0, 100]}
              stroke="var(--color-muted)"
              fontSize={11}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => `${v} %`}
            />
            <YAxis type="category" dataKey="libelle" stroke="var(--color-muted)" fontSize={11} tickLine={false} axisLine={false} width={120} />
            <Tooltip
              formatter={(valeur: unknown) => [formaterTaux(Number(valeur) / 100), 'Avancement moyen']}
              contentStyle={{
                background: 'var(--color-surface)',
                border: '1px solid var(--color-border)',
                borderRadius: 8,
                fontFamily: 'var(--font-texte)',
              }}
              cursor={{ fill: 'var(--color-hover)' }}
            />
            <Bar dataKey="taux" radius={3} maxBarSize={20} isAnimationActive={false}>
              {lignes.map((l) => (
                <Cell key={l.uuid} fill="var(--color-accent)" />
              ))}
              <LabelList
                dataKey="taux"
                position="right"
                formatter={(v: unknown) => formaterTaux(Number(v) / 100)}
                fill="var(--color-text)"
                fontSize={10}
                fontWeight={600}
              />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
      {nombreExclus > 0 && (
        <p className="mt-2 text-xs text-muted">
          {nombreExclus} projet{nombreExclus > 1 ? 's' : ''} non représenté{nombreExclus > 1 ? 's' : ''} (sans activité, ou hors du top {MAX_LIGNES}).
        </p>
      )}
    </div>
  )
}
