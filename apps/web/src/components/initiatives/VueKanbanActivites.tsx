import { Link } from 'react-router'
import { LIBELLES_STATUT_INITIATIVE, ORDRE_STATUT_INITIATIVE, type ActiviteAvecProjet } from '@/lib/initiatives'
import { BarreAtteinte } from '@/components/BarreAtteinte'

interface VueKanbanActivitesProps {
  activitesFiltrees: ActiviteAvecProjet[]
}

/** Mêmes teintes que PastilleStatutInitiative — en-tête de colonne coloré par statut. */
const CLASSES_COLONNE: Record<string, string> = {
  planifiee: 'text-statut-planifiee',
  en_cours: 'text-accent',
  a_risque: 'text-bad',
  en_retard: 'text-eyebrow',
  terminee: 'text-success',
}

/**
 * Colonnes par statut calculé — en lecture seule : le statut d'une
 * activité est entièrement dérivé des dates et de l'avancement de ses
 * tâches (StatutInitiativeCalculator), il n'existe donc aucune action
 * serveur à déclencher en glissant une carte d'une colonne à l'autre.
 */
export function VueKanbanActivites({ activitesFiltrees }: VueKanbanActivitesProps) {
  if (activitesFiltrees.length === 0) {
    return <p className="text-muted">Aucune activité ne correspond à ces filtres.</p>
  }

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
      {ORDRE_STATUT_INITIATIVE.map((statut) => {
        const activites = activitesFiltrees.filter((a) => a.statut === statut)

        return (
          <div key={statut} className="flex flex-col gap-2 rounded-[var(--radius-carte)] bg-nested p-3">
            <p className={`text-xs font-semibold uppercase tracking-wide ${CLASSES_COLONNE[statut]}`}>
              {LIBELLES_STATUT_INITIATIVE[statut]} <span className="chiffres text-muted">({activites.length})</span>
            </p>
            <div className="flex max-h-[32rem] flex-col gap-2 overflow-y-auto">
              {activites.length === 0 ? (
                <p className="text-xs text-muted">—</p>
              ) : (
                activites.map((a) => (
                  <Link
                    key={a.uuid}
                    to={`/initiatives/${a.initiativeUuid}`}
                    className="rounded-[var(--radius-controle)] border border-border bg-surface p-3 text-sm shadow-[var(--shadow-carte)] transition-colors hover:border-accent"
                  >
                    <p className="truncate text-[10px] font-semibold uppercase tracking-wide text-eyebrow">{a.initiativeLibelle}</p>
                    <p className="font-medium">{a.libelle}</p>
                    {a.responsables.length > 0 && (
                      <p className="mt-1 truncate text-xs text-muted">{a.responsables.map((r) => r.nomComplet).join(', ')}</p>
                    )}
                    <div className="mt-2">
                      <BarreAtteinte taux={a.avancement} />
                    </div>
                  </Link>
                ))
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}
