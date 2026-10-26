import { BarreExecution } from '@/components/BarreExecution'
import type { NoeudComposante } from '@/lib/composantes'
import { formaterMds } from '@/lib/format'

const NOMBRE_AFFICHE = 8

interface Agregat {
  sigle: string
  activites: number
  notifie: number
  engage: number
  depense: number
}

/**
 * Exécution budgétaire par structure responsable d'activité — les plus
 * dotées d'abord. Agrégée depuis l'arbre déjà chargé (activités et leurs
 * montants), donc dans le périmètre de l'utilisateur.
 */
export function ExecutionParStructure({ composantes, tauxAttendu }: { composantes: NoeudComposante[]; tauxAttendu?: number | null }) {
  const parStructure = new Map<string, Agregat>()
  for (const c of composantes) {
    for (const sc of c.sousComposantes ?? []) {
      for (const a of sc.activites ?? []) {
        const sigle = a.structureResponsable.sigle
        const agregat = parStructure.get(sigle) ?? { sigle, activites: 0, notifie: 0, engage: 0, depense: 0 }
        agregat.activites += 1
        agregat.notifie += a.notifie ?? 0
        agregat.engage += a.engage ?? 0
        agregat.depense += a.depense ?? 0
        parStructure.set(sigle, agregat)
      }
    }
  }
  const classement = [...parStructure.values()].filter((s) => s.notifie > 0).sort((a, b) => b.notifie - a.notifie)

  if (classement.length === 0) {
    return <p className="text-sm text-muted">Aucune activité dotée dans ce périmètre.</p>
  }

  return (
    <div className="flex flex-col gap-3">
      <ul className="flex flex-col gap-3">
        {classement.slice(0, NOMBRE_AFFICHE).map((s) => (
          <li key={s.sigle}>
            <div className="mb-1 flex items-baseline justify-between gap-2 text-sm">
              <span className="truncate font-semibold">
                {s.sigle}
                <span className="ml-1.5 text-xs font-normal text-muted">
                  {s.activites} activité{s.activites > 1 ? 's' : ''}
                </span>
              </span>
              <span className="chiffres shrink-0 text-xs text-muted">{formaterMds(s.notifie)} Mds notifiés</span>
            </div>
            <BarreExecution notifie={s.notifie} engage={s.engage} depense={s.depense} rythmeAttendu={tauxAttendu} />
          </li>
        ))}
      </ul>
      {classement.length > NOMBRE_AFFICHE && (
        <p className="text-xs text-muted">
          + {classement.length - NOMBRE_AFFICHE} autre{classement.length - NOMBRE_AFFICHE > 1 ? 's' : ''} structure
          {classement.length - NOMBRE_AFFICHE > 1 ? 's' : ''}
        </p>
      )}
    </div>
  )
}
