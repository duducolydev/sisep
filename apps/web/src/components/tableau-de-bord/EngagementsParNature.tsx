import { NATURES_ENGAGEMENT } from '@/lib/budget'
import type { EngagementsNature } from '@/lib/tableau-de-bord'
import { formaterFcfa, formaterTaux } from '@/lib/format'

const LIBELLES = Object.fromEntries(NATURES_ENGAGEMENT.map((n) => [n.valeur, n.libelle]))

/**
 * Répartition des engagements validés de l'exercice par nature (travaux,
 * fournitures, consultations…), la plus lourde d'abord. Un classement plutôt
 * qu'un graphique circulaire : les montants se comparent sur une même
 * échelle, et chaque ligne porte son montant et sa part en clair.
 */
export function EngagementsParNature({ lignes }: { lignes: EngagementsNature[] }) {
  const total = lignes.reduce((somme, l) => somme + l.montant, 0)
  if (total === 0) {
    return <p className="text-sm text-muted">Aucun engagement validé sur cet exercice.</p>
  }
  const maximum = Math.max(...lignes.map((l) => l.montant))

  return (
    <ul className="flex flex-col gap-2.5">
      {lignes.map((l) => (
        <li key={l.nature} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 text-sm">
          <span className="truncate" title={LIBELLES[l.nature] ?? l.nature}>
            {LIBELLES[l.nature] ?? l.nature}
            <span className="ml-1.5 text-xs text-muted">
              {l.nombre} engagement{l.nombre > 1 ? 's' : ''}
            </span>
          </span>
          <span className="chiffres text-right">
            {formaterFcfa(l.montant)} <span className="text-xs text-muted">· {formaterTaux(l.montant / total)}</span>
          </span>
          <span className="col-span-2 h-2 overflow-hidden rounded-[var(--radius-barre)] bg-track" aria-hidden="true">
            <span className="block h-full rounded-[var(--radius-barre)] bg-accent" style={{ width: `${(l.montant / maximum) * 100}%` }} />
          </span>
        </li>
      ))}
    </ul>
  )
}
