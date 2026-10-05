import { Bar, BarChart, CartesianGrid, Cell, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import {
  COULEURS_CSS_STATUT_INITIATIVE,
  LIBELLES_STATUT_INITIATIVE,
  ORDRE_STATUT_INITIATIVE,
  type StatutInitiativeActivite,
} from '@/lib/initiatives'

interface RepartitionStatutsInitiativesProps {
  statuts: StatutInitiativeActivite[]
}

/** Répartition des activités de projet par statut — une barre par statut, toutes les activités de tous les projets confondues. */
export function RepartitionStatutsInitiatives({ statuts }: RepartitionStatutsInitiativesProps) {
  if (statuts.length === 0) {
    return <p className="text-muted">Aucune activité pour le moment.</p>
  }

  const donnees = ORDRE_STATUT_INITIATIVE.map((statut) => ({
    statut,
    libelle: LIBELLES_STATUT_INITIATIVE[statut],
    nombre: statuts.filter((s) => s === statut).length,
  })).filter((d) => d.nombre > 0)

  return (
    <div className="h-64 w-full">
      <ResponsiveContainer width="100%" height="100%" minWidth={200} minHeight={180}>
        <BarChart data={donnees} margin={{ top: 24, right: 16, bottom: 0, left: 0 }} barCategoryGap="20%">
          <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="libelle" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
          <YAxis stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} allowDecimals={false} />
          <Bar dataKey="nombre" radius={[4, 4, 0, 0]} maxBarSize={64} isAnimationActive={false}>
            {donnees.map((d) => (
              <Cell key={d.statut} fill={COULEURS_CSS_STATUT_INITIATIVE[d.statut]} />
            ))}
            <LabelList dataKey="nombre" position="top" fill="var(--color-text)" fontSize={12} fontWeight={600} />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}
