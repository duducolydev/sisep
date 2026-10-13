import type { ReactNode } from 'react'
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  COULEURS_CSS_STATUT_INITIATIVE,
  LIBELLES_STATUT_INITIATIVE,
  ORDRE_STATUT_INITIATIVE,
  type ActiviteAvecProjet,
  type StatutInitiativeActivite,
} from '@/lib/initiatives'
import { formaterTaux } from '@/lib/format'
import { hauteurGraphiqueEnBarres } from '@/lib/graphiques'

interface ProgressionParActiviteProps {
  activites: ActiviteAvecProjet[]
}

interface LigneActivite {
  uuid: string
  libelle: string
  initiativeLibelle: string
  statut: StatutInitiativeActivite
  taux: number
}

const HAUTEUR_LIGNE = 28
const MAX_LIGNES = 12

/** Une barre par activité, triée par avancement décroissant — couleur = statut calculé, cohérent avec les pastilles et le graphique de répartition. */
export function ProgressionParActivite({ activites }: ProgressionParActiviteProps) {
  const avecAvancement: LigneActivite[] = activites
    .filter((a): a is ActiviteAvecProjet & { avancement: number } => a.avancement !== null)
    .map((a) => ({ uuid: a.uuid, libelle: a.libelle, initiativeLibelle: a.initiativeLibelle, statut: a.statut, taux: a.avancement * 100 }))
    .sort((a, b) => b.taux - a.taux)

  const lignes = avecAvancement.slice(0, MAX_LIGNES)
  const nombreExclues = activites.length - lignes.length

  if (lignes.length === 0) {
    return <p className="text-sm text-muted">Aucune activité avec une tâche pour le moment.</p>
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
              formatter={(valeur: unknown) => [formaterTaux(Number(valeur) / 100), 'Avancement']}
              labelFormatter={(libelle: ReactNode, items) => {
                const ligne = (items?.[0]?.payload ?? null) as LigneActivite | null
                return ligne ? `${ligne.initiativeLibelle} — ${ligne.libelle}` : libelle
              }}
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
                <Cell key={l.uuid} fill={COULEURS_CSS_STATUT_INITIATIVE[l.statut]} />
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
      <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-muted">
        {ORDRE_STATUT_INITIATIVE.map((statut) => (
          <span key={statut} className="flex items-center gap-1.5">
            <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: COULEURS_CSS_STATUT_INITIATIVE[statut] }} aria-hidden="true" />
            {LIBELLES_STATUT_INITIATIVE[statut]}
          </span>
        ))}
        {nombreExclues > 0 && <span>{nombreExclues} activité{nombreExclues > 1 ? 's' : ''} non représentée{nombreExclues > 1 ? 's' : ''} (sans tâche, ou hors du top {MAX_LIGNES}).</span>}
      </div>
    </div>
  )
}
