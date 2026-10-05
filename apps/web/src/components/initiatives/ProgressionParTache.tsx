import type { ReactNode } from 'react'
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { COULEURS_CSS_STATUT_INITIATIVE, LIBELLES_STATUT_INITIATIVE, ORDRE_STATUT_INITIATIVE, type StatutInitiativeActivite } from '@/lib/initiatives'
import { formaterTaux } from '@/lib/format'

export interface TacheAvecContexte {
  uuid: string
  libelle: string
  statut: StatutInitiativeActivite
  activiteLibelle: string
  initiativeLibelle: string
  terminee: boolean
}

interface ProgressionParTacheProps {
  taches: TacheAvecContexte[]
}

interface LigneTache {
  uuid: string
  libelle: string
  activiteLibelle: string
  initiativeLibelle: string
  statut: StatutInitiativeActivite
  taux: number
}

const HAUTEUR_LIGNE = 24
const MAX_LIGNES = 15

/** Une barre par tâche, 0 % ou 100 % (une tâche n'a pas d'avancement intermédiaire) — couleur = statut calculé, cohérent avec les autres graphiques de progression. */
export function ProgressionParTache({ taches }: ProgressionParTacheProps) {
  const toutes: LigneTache[] = taches
    .map((t) => ({
      uuid: t.uuid,
      libelle: t.libelle,
      activiteLibelle: t.activiteLibelle,
      initiativeLibelle: t.initiativeLibelle,
      statut: t.statut,
      taux: t.terminee ? 100 : 0,
    }))
    .sort((a, b) => b.taux - a.taux)

  const lignes = toutes.slice(0, MAX_LIGNES)
  const nombreExclues = taches.length - lignes.length

  if (lignes.length === 0) {
    return <p className="text-sm text-muted">Aucune tâche pour le moment.</p>
  }

  return (
    <div>
      <div style={{ height: lignes.length * HAUTEUR_LIGNE + 24 }}>
        <ResponsiveContainer width="100%" height="100%" minWidth={200} minHeight={160}>
          <BarChart data={lignes} layout="vertical" margin={{ top: 8, right: 44, bottom: 0, left: 8 }} barCategoryGap={4}>
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
              formatter={(valeur: unknown) => [formaterTaux(Number(valeur) / 100), 'Progression']}
              labelFormatter={(libelle: ReactNode, items) => {
                const ligne = (items?.[0]?.payload ?? null) as LigneTache | null
                return ligne ? `${ligne.initiativeLibelle} — ${ligne.activiteLibelle} — ${ligne.libelle}` : libelle
              }}
              contentStyle={{
                background: 'var(--color-surface)',
                border: '1px solid var(--color-border)',
                borderRadius: 8,
                fontFamily: 'var(--font-texte)',
              }}
              cursor={{ fill: 'var(--color-hover)' }}
            />
            <Bar dataKey="taux" radius={3} maxBarSize={16} isAnimationActive={false}>
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
        {nombreExclues > 0 && <span>{nombreExclues} tâche{nombreExclues > 1 ? 's' : ''} non représentée{nombreExclues > 1 ? 's' : ''} (hors du top {MAX_LIGNES}).</span>}
      </div>
    </div>
  )
}
