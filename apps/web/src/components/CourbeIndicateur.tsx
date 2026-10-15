import { useState } from 'react'
import { CartesianGrid, Line, LineChart, ReferenceDot, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formaterValeurIndicateur } from '@/lib/format'
import { agregerSerie, type GranulariteSerie, type PointSerie } from '@/lib/serie-indicateur'

interface CourbeIndicateurProps {
  /** Valeurs validées et en attente, dans n'importe quel ordre. */
  points: PointSerie[]
  unite: string
  /** Taux d'atteinte (0-1) — colore la courbe comme sur la maquette (bon/vigilance/critique). */
  tauxAtteinte?: number | null
}

const GRANULARITES: { valeur: GranulariteSerie; libelle: string }[] = [
  { valeur: 'saisie', libelle: 'Par saisie' },
  { valeur: 'trimestre', libelle: 'Par trimestre' },
  { valeur: 'annee', libelle: 'Par année' },
]

/**
 * Couleur par seuil d'atteinte, comme la maquette (§ 9.4) : ≥60 % tenu,
 * ≥45 % vigilance, sinon critique.
 *
 * Deux substitutions mesurées : --color-ok vaut --color-accent, donc un
 * point ≥60 % était de la même couleur qu'un point sans taux connu ; et
 * --color-warn ne se distingue pas de --color-bad en vision normale
 * (ΔE 9,9), le même écueil que tokens.css documente pour les statuts du
 * module Projets, résolu de la même façon par --color-eyebrow.
 */
function couleurAtteinte(tauxAtteinte: number | null | undefined): string {
  if (tauxAtteinte === null || tauxAtteinte === undefined) return 'var(--color-accent)'
  const pourcent = tauxAtteinte * 100
  if (pourcent >= 60) return 'var(--color-success)'
  if (pourcent >= 45) return 'var(--color-eyebrow)'

  return 'var(--color-bad)'
}

/**
 * Courbe d'évolution d'un indicateur — § 2.4, § 9.4 du brief. Les valeurs
 * en attente de validation prolongent la courbe en pointillé (retours des
 * RSE : la saisie doit se voir aussitôt), la partie validée restant pleine.
 */
export function CourbeIndicateur({ points, unite, tauxAtteinte }: CourbeIndicateurProps) {
  const [granularite, setGranularite] = useState<GranulariteSerie>('saisie')

  if (points.length === 0) {
    return <p className="text-muted">Aucune valeur saisie pour le moment.</p>
  }

  const serie = agregerSerie(points, granularite)
  const premierEnAttente = serie.findIndex((p) => p.enAttente)
  // Le pointillé part du dernier point validé, pour que les deux traits se rejoignent.
  const debutPointille = premierEnAttente === -1 ? serie.length : Math.max(0, premierEnAttente - 1)
  const donnees = serie.map((p, i) => ({
    ...p,
    validee: premierEnAttente === -1 || i < premierEnAttente ? p.valeur : undefined,
    provisoire: i >= debutPointille ? p.valeur : undefined,
  }))

  const couleur = couleurAtteinte(tauxAtteinte)
  const dernierPoint = serie[serie.length - 1]

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex gap-0.5 rounded-[var(--radius-controle)] bg-nested p-0.5 text-xs" role="group" aria-label="Regroupement">
          {GRANULARITES.map((g) => (
            <button
              key={g.valeur}
              type="button"
              aria-pressed={granularite === g.valeur}
              onClick={() => setGranularite(g.valeur)}
              className={`rounded-[calc(var(--radius-controle)-2px)] px-2.5 py-1.5 font-semibold transition-colors ${
                granularite === g.valeur ? 'bg-accent text-white' : 'text-muted hover:text-accent'
              }`}
            >
              {g.libelle}
            </button>
          ))}
        </div>
        {premierEnAttente !== -1 && (
          <p className="flex items-center gap-2 text-xs text-muted">
            <svg width="22" height="6" aria-hidden="true">
              <line x1="0" y1="3" x2="22" y2="3" stroke={couleur} strokeWidth="2" strokeDasharray="4 3" />
            </svg>
            En attente de validation
          </p>
        )}
      </div>

      <div className="h-56 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={donnees} margin={{ top: 16, right: 24, bottom: 0, left: 8 }}>
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="libelle" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
            <YAxis
              stroke="var(--color-muted)"
              fontSize={12}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => formaterValeurIndicateur(v)}
            />
            <Tooltip
              formatter={(valeur: unknown, _nom: unknown, element: { payload?: { enAttente?: boolean } }) => [
                `${formaterValeurIndicateur(Number(valeur))} ${unite}${element.payload?.enAttente ? ' (en attente de validation)' : ''}`,
                'Valeur',
              ]}
              contentStyle={{
                background: 'var(--color-surface)',
                border: '1px solid var(--color-border)',
                borderRadius: 8,
                fontFamily: 'var(--font-texte)',
              }}
            />
            <Line
              type="monotone"
              dataKey="validee"
              stroke={couleur}
              strokeWidth={2.5}
              strokeLinecap="round"
              dot={serie.length === 1 ? { r: 3, fill: couleur, stroke: 'none' } : false}
              connectNulls={false}
              isAnimationActive={false}
            />
            <Line
              type="monotone"
              dataKey="provisoire"
              stroke={couleur}
              strokeWidth={2}
              strokeDasharray="5 4"
              dot={false}
              connectNulls={false}
              isAnimationActive={false}
              legendType="none"
              tooltipType="none"
            />
            <ReferenceDot
              x={dernierPoint.libelle}
              y={dernierPoint.valeur}
              r={4.5}
              fill={dernierPoint.enAttente ? 'var(--color-surface)' : couleur}
              stroke={couleur}
              strokeWidth={2}
              label={{
                value: `${formaterValeurIndicateur(dernierPoint.valeur)} ${unite}`,
                // À gauche du point : le dernier point est au bord droit, une étiquette centrée y serait coupée.
                position: 'left',
                fill: 'var(--color-text)',
                fontSize: 11,
                fontWeight: 700,
              }}
            />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </div>
  )
}
