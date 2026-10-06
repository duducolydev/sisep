import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import { formaterValeurIndicateur } from '@/lib/format'

interface BarresObjectifsIndicateurProps {
  valeurReference: string
  actuel: string | null
  valeurIntermediaire: string | null
  valeurCible: string
  unite: string
}

/*
 * Quatre repères, quatre teintes réellement distinctes — mesurées, pas
 * choisies à l'œil. Deux corrections par rapport à la première version :
 * « Actuel » valait --color-ok, qui est exactement --color-accent de
 * « Cible » (écart nul, deux barres identiques), et « Référence » portait
 * --color-muted, le gris de texte, trop proche du reste en vision normale
 * (ΔE 11 contre un plancher de 15). Le couple le plus serré de ce jeu-ci
 * tient 18,6 en vision normale et 6,6 en protanopie, admis parce que chaque
 * barre porte sa valeur en clair et son intitulé en abscisse : l'identité ne
 * repose jamais sur la couleur seule.
 */
const COULEURS: Record<string, string> = {
  Référence: 'var(--color-serie-2)',
  Actuel: 'var(--color-success)',
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
