import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { ActiviteAvecProjet } from '@/lib/initiatives'
import { hauteurGraphiqueEnBarres } from '@/lib/graphiques'

interface ChargeParResponsableProps {
  activites: ActiviteAvecProjet[]
}

interface LigneResponsable {
  uuid: string
  nom: string
  nombre: number
}

const HAUTEUR_LIGNE = 26
const MAX_LIGNES = 10

/** Nombre d'activités non terminées par responsable — repère une éventuelle surcharge, comptage donc une seule teinte (magnitude, pas de statut par barre). */
export function ChargeParResponsable({ activites }: ChargeParResponsableProps) {
  const comptes = new Map<string, LigneResponsable>()
  for (const a of activites) {
    if ('terminee' === a.statut) continue
    for (const r of a.responsables) {
      const existant = comptes.get(r.uuid)
      if (existant) existant.nombre += 1
      else comptes.set(r.uuid, { uuid: r.uuid, nom: r.nomComplet, nombre: 1 })
    }
  }

  const toutes = [...comptes.values()].sort((a, b) => b.nombre - a.nombre)
  const lignes = toutes.slice(0, MAX_LIGNES)
  const nombreExclus = toutes.length - lignes.length

  if (lignes.length === 0) {
    return <p className="text-sm text-muted">Aucune activité en cours avec un responsable assigné.</p>
  }

  return (
    <div>
      <div style={{ height: hauteurGraphiqueEnBarres(lignes.length, HAUTEUR_LIGNE) }}>
        <ResponsiveContainer width="100%" height="100%" minWidth={200}>
          <BarChart data={lignes} layout="vertical" margin={{ top: 8, right: 24, bottom: 0, left: 8 }} barCategoryGap={6}>
            <XAxis type="number" allowDecimals={false} stroke="var(--color-muted)" fontSize={11} tickLine={false} axisLine={false} />
            <YAxis type="category" dataKey="nom" stroke="var(--color-muted)" fontSize={11} tickLine={false} axisLine={false} width={120} />
            <Tooltip
              formatter={(valeur: unknown) => [`${valeur}`, 'Activités non terminées']}
              contentStyle={{
                background: 'var(--color-surface)',
                border: '1px solid var(--color-border)',
                borderRadius: 8,
                fontFamily: 'var(--font-texte)',
              }}
              cursor={{ fill: 'var(--color-hover)' }}
            />
            <Bar dataKey="nombre" radius={3} maxBarSize={18} isAnimationActive={false}>
              {lignes.map((l) => (
                <Cell key={l.uuid} fill="var(--color-accent-clair)" />
              ))}
              <LabelList dataKey="nombre" position="right" fill="var(--color-text)" fontSize={10} fontWeight={600} />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
      {nombreExclus > 0 && <p className="mt-2 text-xs text-muted">+{nombreExclus} autre{nombreExclus > 1 ? 's' : ''} responsable{nombreExclus > 1 ? 's' : ''}.</p>}
    </div>
  )
}
