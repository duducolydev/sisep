import type { ReactNode } from 'react'
import { Bar, BarChart, Cell, LabelList, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { Indicateur } from '@/lib/indicateurs'
import { formaterTaux } from '@/lib/format'
import { hauteurGraphiqueEnBarres } from '@/lib/graphiques'

interface AtteinteGlobaleIndicateursProps {
  indicateurs: Indicateur[]
}

interface LigneAtteinte {
  uuid: string
  code: string
  libelle: string
  taux: number
}

const HAUTEUR_LIGNE = 28

/**
 * Vue d'ensemble : position de chaque indicateur entre sa référence (0 %) et
 * sa cible (100 %), tous types d'unités confondus — même calcul que la
 * colonne « Taux d'atteinte » du tableau (§ 5.2), affiché en une seule fois
 * pour repérer les écarts sans dépendre de l'unité de chaque indicateur.
 */
export function AtteinteGlobaleIndicateurs({ indicateurs }: AtteinteGlobaleIndicateursProps) {
  const lignes: LigneAtteinte[] = indicateurs
    .filter((i) => i.tauxAtteinte !== null)
    .map((i) => ({ uuid: i.uuid, code: i.code, libelle: i.libelle, taux: (i.tauxAtteinte ?? 0) * 100 }))

  const nombreExclus = indicateurs.length - lignes.length

  if (lignes.length === 0) {
    return <p className="text-sm text-muted">Aucun indicateur avec une valeur actuelle saisie pour le moment.</p>
  }

  const valeurs = lignes.map((l) => l.taux)
  const domaineMax = Math.max(100, Math.ceil(Math.max(...valeurs) / 25) * 25)
  const domaineMin = Math.min(0, Math.floor(Math.min(...valeurs) / 25) * 25)
  const yATdesRegresses = lignes.some((l) => l.taux < 0)

  return (
    <div>
      <div style={{ height: hauteurGraphiqueEnBarres(lignes.length, HAUTEUR_LIGNE) }}>
        <ResponsiveContainer width="100%" height="100%" minWidth={200}>
          <BarChart data={lignes} layout="vertical" margin={{ top: 8, right: 44, bottom: 0, left: 8 }} barCategoryGap={6}>
            <XAxis
              type="number"
              domain={[domaineMin, domaineMax]}
              stroke="var(--color-muted)"
              fontSize={11}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => `${v} %`}
            />
            <YAxis type="category" dataKey="code" stroke="var(--color-muted)" fontSize={11} tickLine={false} axisLine={false} width={64} />
            <ReferenceLine
              x={100}
              stroke="var(--color-muted)"
              strokeDasharray="3 3"
              label={{ value: 'Cible', position: 'insideTopRight', fill: 'var(--color-muted)', fontSize: 10 }}
            />
            <Tooltip
              formatter={(valeur: unknown) => [formaterTaux(Number(valeur) / 100), "Taux d'atteinte"]}
              labelFormatter={(code: ReactNode, items) => {
                const ligne = (items?.[0]?.payload ?? null) as LigneAtteinte | null
                return ligne ? `${ligne.code} — ${ligne.libelle}` : code
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
                <Cell key={l.uuid} fill={l.taux < 0 ? 'var(--color-bad)' : l.taux >= 100 ? 'var(--color-success)' : 'var(--color-accent)'} />
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
      <div className="mt-1 flex flex-wrap items-center gap-4 text-xs text-muted">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent" aria-hidden="true" />
          En cours
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm bg-success" aria-hidden="true" />
          Cible atteinte ou dépassée
        </span>
        {yATdesRegresses && (
          <span className="flex items-center gap-1.5">
            <span className="inline-block h-2.5 w-2.5 rounded-sm bg-bad" aria-hidden="true" />
            En dessous de la référence
          </span>
        )}
        {nombreExclus > 0 && (
          <span>
            {nombreExclus} indicateur{nombreExclus > 1 ? 's' : ''} sans valeur actuelle non représenté{nombreExclus > 1 ? 's' : ''}.
          </span>
        )}
      </div>
    </div>
  )
}
