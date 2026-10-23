import { useId, useState } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, LabelList, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formaterDate, formaterValeurIndicateur } from '@/lib/format'
import { agregerSerie, type GranulariteSerie, type PointSerie } from '@/lib/serie-indicateur'

interface EvolutionIndicateurProps {
  /** Valeurs validées et en attente, dans n'importe quel ordre. */
  points: PointSerie[]
  unite: string
  /** Taux d'atteinte (0-1) — colore les barres comme sur la maquette (bon/vigilance/critique). */
  tauxAtteinte?: number | null
  /** Valeur cible et son échéance, tracées en ligne de repère. */
  valeurCible?: number | null
  dateCible?: string | null
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
 * Deux substitutions mesurées : --color-ok vaut --color-accent, donc une
 * barre ≥60 % était de la même couleur qu'une barre sans taux connu ; et
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
 * Évolution d'un indicateur en barres — § 2.4, § 9.4 du brief ; les barres
 * remplacent la courbe à la demande du PHASAOC (présentation d'octobre
 * 2026) : une valeur par période se compare mieux d'un coup d'œil.
 *
 * Une barre en attente de validation est hachurée et cernée (retours des
 * RSE : la saisie doit se voir aussitôt), une barre validée est pleine — la
 * distinction ne repose pas sur la seule couleur. La cible, quand elle est
 * connue, est une ligne de repère sur le même axe.
 */
export function EvolutionIndicateur({ points, unite, tauxAtteinte, valeurCible, dateCible }: EvolutionIndicateurProps) {
  const [granularite, setGranularite] = useState<GranulariteSerie>('saisie')
  const idHachures = `hachures-${useId().replace(/:/g, '')}`

  if (points.length === 0) {
    return <p className="text-muted">Aucune valeur saisie pour le moment.</p>
  }

  const serie = agregerSerie(points, granularite)
  const couleur = couleurAtteinte(tauxAtteinte)
  const dernier = serie.length - 1
  const unAuMoinsEnAttente = serie.some((p) => p.enAttente)
  const cibleConnue = valeurCible !== null && valeurCible !== undefined && Number.isFinite(valeurCible)

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
        <div className="flex flex-wrap items-center gap-4 text-xs text-muted">
          {unAuMoinsEnAttente && (
            <p className="flex items-center gap-2">
              <svg width="14" height="14" aria-hidden="true">
                <rect x="1" y="1" width="12" height="12" rx="2" fill={`url(#${idHachures})`} stroke={couleur} strokeWidth="1.5" />
              </svg>
              En attente de validation
            </p>
          )}
          {cibleConnue && (
            <p className="flex items-center gap-2">
              <svg width="22" height="6" aria-hidden="true">
                <line x1="0" y1="3" x2="22" y2="3" stroke="var(--color-muted)" strokeWidth="1.5" strokeDasharray="4 3" />
              </svg>
              Cible
            </p>
          )}
        </div>
      </div>

      {/* Motif hors du graphique : la légende ci-dessus s'en sert aussi. */}
      <svg width="0" height="0" className="absolute" aria-hidden="true">
        <defs>
          <pattern id={idHachures} width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
            <rect width="6" height="6" fill="var(--color-surface)" />
            <line x1="0" y1="0" x2="0" y2="6" stroke={couleur} strokeWidth="2.5" />
          </pattern>
        </defs>
      </svg>

      <div className="h-56 w-full">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={serie} margin={{ top: 20, right: 16, bottom: 0, left: 8 }} barCategoryGap="30%">
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="libelle" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
            {/* Chiffres en police à chasse fixe — graduations, étiquette de la
                cible, infobulle : en Public Sans, l'espace fine insécable des
                milliers (fr-FR) fait 2 px et « 40 000 » se lisait « 40000 ».
                Largeur de l'axe ajustée aux valeurs, plus larges. */}
            <YAxis
              width="auto"
              stroke="var(--color-muted)"
              fontSize={12}
              tick={{ fontFamily: 'var(--font-chiffres)' }}
              tickLine={false}
              axisLine={false}
              tickFormatter={(v: number) => formaterValeurIndicateur(v)}
            />
            <Tooltip
              cursor={{ fill: 'var(--color-hover)' }}
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
              itemStyle={{ fontFamily: 'var(--font-chiffres)' }}
            />
            {cibleConnue && (
              <ReferenceLine
                y={valeurCible}
                stroke="var(--color-muted)"
                strokeWidth={1.5}
                strokeDasharray="4 3"
                ifOverflow="extendDomain"
                label={{
                  value: `Cible${dateCible ? ` ${formaterDate(dateCible)}` : ''} : ${formaterValeurIndicateur(valeurCible)} ${unite}`,
                  position: 'insideTopLeft',
                  fill: 'var(--color-muted)',
                  fontSize: 11,
                  fontFamily: 'var(--font-chiffres)',
                }}
              />
            )}
            <Bar dataKey="valeur" radius={[4, 4, 0, 0]} maxBarSize={48} isAnimationActive={false}>
              {serie.map((p) => (
                <Cell
                  key={p.libelle}
                  fill={p.enAttente ? `url(#${idHachures})` : couleur}
                  stroke={p.enAttente ? couleur : 'none'}
                  strokeWidth={p.enAttente ? 1.5 : 0}
                />
              ))}
              {/* Seule la dernière valeur est écrite : le survol donne les autres. */}
              <LabelList
                dataKey="valeur"
                content={(props) => {
                  const { index, x, y, width, value } = props as { index?: number; x?: number | string; y?: number | string; width?: number | string; value?: unknown }
                  if (index !== dernier) return null

                  return (
                    <text
                      x={Number(x) + Number(width) / 2}
                      y={Number(y) - 6}
                      textAnchor="middle"
                      fill="var(--color-text)"
                      fontSize={11}
                      fontWeight={700}
                      className="chiffres"
                    >
                      {formaterValeurIndicateur(Number(value))} {unite}
                    </text>
                  )
                }}
              />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  )
}
