import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import { formaterValeurIndicateur } from '@/lib/format'

interface BarresObjectifsIndicateurProps {
  valeurReference: string
  actuel: string | null
  valeurIntermediaire: string | null
  valeurCible: string
  unite: string
}

const COULEURS: Record<string, string> = {
  Référence: 'var(--color-muted)',
  Actuel: 'var(--color-ok)',
  'Objectif intermédiaire': 'var(--color-accent-clair)',
  Cible: 'var(--color-accent)',
}

/** Référence / Actuel / Objectif intermédiaire (optionnel) / Cible — mêmes valeurs que le <dl> voisin, en repère visuel. */
export function BarresObjectifsIndicateur({
  valeurReference,
  actuel,
  valeurIntermediaire,
  valeurCible,
  unite,
}: BarresObjectifsIndicateurProps) {
  const donnees = [
    { libelle: 'Référence', valeur: Number(valeurReference) },
    ...(actuel !== null ? [{ libelle: 'Actuel', valeur: Number(actuel) }] : []),
    ...(valeurIntermediaire !== null ? [{ libelle: 'Objectif intermédiaire', valeur: Number(valeurIntermediaire) }] : []),
    { libelle: 'Cible', valeur: Number(valeurCible) },
  ]

  return (
    <div className="h-56 w-full">
      <ResponsiveContainer width="100%" height="100%" minWidth={200} minHeight={160}>
        <BarChart data={donnees} margin={{ top: 20, right: 8, bottom: 0, left: 0 }}>
          <XAxis dataKey="libelle" stroke="var(--color-muted)" fontSize={11} tickLine={false} axisLine={false} />
          <YAxis hide />
          <Bar dataKey="valeur" radius={[4, 4, 0, 0]} maxBarSize={64} isAnimationActive={false}>
            {donnees.map((d) => (
              <Cell key={d.libelle} fill={COULEURS[d.libelle] ?? 'var(--color-accent)'} />
            ))}
            <LabelList
              dataKey="valeur"
              position="top"
              formatter={(v: unknown) => `${formaterValeurIndicateur(Number(v))} ${unite}`}
              fill="var(--color-text)"
              fontSize={11}
              fontWeight={600}
            />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}
