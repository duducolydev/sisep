import type { NoeudComposante } from '@/lib/composantes'

/** Ordre de lecture du cycle de vie, et teinte de chaque statut (texte toujours en clair à côté). */
const STATUTS = [
  { valeur: 'planifiee', libelle: 'Planifiées', couleur: 'var(--color-statut-planifiee)' },
  { valeur: 'en_cours', libelle: 'En cours', couleur: 'var(--color-accent)' },
  { valeur: 'terminee', libelle: 'Terminées', couleur: 'var(--color-success)' },
  { valeur: 'suspendue', libelle: 'Suspendues', couleur: 'var(--color-warn)' },
  { valeur: 'annulee', libelle: 'Annulées', couleur: 'var(--color-bad)' },
] as const

/**
 * Activités du PTBA par statut (cycle de vie saisi : planifiée, en cours,
 * terminée…) — une barre empilée et le décompte de chaque statut, dans le
 * périmètre de l'utilisateur.
 */
export function RepartitionStatutsActivites({ composantes }: { composantes: NoeudComposante[] }) {
  const comptes = new Map<string, number>()
  let total = 0
  for (const c of composantes) {
    for (const sc of c.sousComposantes ?? []) {
      for (const a of sc.activites ?? []) {
        comptes.set(a.statut, (comptes.get(a.statut) ?? 0) + 1)
        total += 1
      }
    }
  }

  if (total === 0) {
    return <p className="text-sm text-muted">Aucune activité dans ce périmètre.</p>
  }

  const segments = STATUTS.map((s) => ({ ...s, nombre: comptes.get(s.valeur) ?? 0 })).filter((s) => s.nombre > 0)

  return (
    <div className="flex flex-col gap-3">
      <div className="flex h-3 w-full gap-[2px] overflow-hidden rounded-[var(--radius-barre)]" role="img" aria-label={segments.map((s) => `${s.nombre} ${s.libelle.toLowerCase()}`).join(', ')}>
        {segments.map((s) => (
          <span key={s.valeur} className="h-full first:rounded-l-[var(--radius-barre)] last:rounded-r-[var(--radius-barre)]" style={{ width: `${(s.nombre / total) * 100}%`, background: s.couleur }} title={`${s.libelle} : ${s.nombre}`} />
        ))}
      </div>
      <ul className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
        {segments.map((s) => (
          <li key={s.valeur} className="flex items-center justify-between gap-2">
            <span className="flex items-center gap-1.5">
              <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: s.couleur }} aria-hidden="true" />
              {s.libelle}
            </span>
            <span className="chiffres text-muted">
              {s.nombre} <span className="text-xs">({Math.round((s.nombre / total) * 100)} %)</span>
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
