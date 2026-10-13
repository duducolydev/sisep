import { Link } from 'react-router'
import { LIBELLES_STATUT_INITIATIVE, ORDRE_STATUT_INITIATIVE, type ActiviteAvecProjet, type Initiative } from '@/lib/initiatives'
import { BarreAtteinte } from '@/components/BarreAtteinte'

interface VueKanbanActivitesProps {
  activitesFiltrees: ActiviteAvecProjet[]
  /** Projets retenus qui n'ont encore aucune activité, rangés dans leur propre colonne. */
  projetsSansActivite: Initiative[]
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
 *
 * Un projet sans activité n'a pas de statut : il ne peut entrer dans aucune
 * de ces colonnes. Il a la sienne, « Sans activité », placée en tête et
 * affichée seulement quand elle a quelque chose à montrer — une colonne
 * toujours vide n'apprendrait rien.
 */
export function VueKanbanActivites({ activitesFiltrees, projetsSansActivite }: VueKanbanActivitesProps) {
  if (activitesFiltrees.length === 0 && projetsSansActivite.length === 0) {
    return <p className="text-muted">Aucun projet ni aucune activité ne correspond à ces filtres.</p>
  }

  const avecColonneSansActivite = projetsSansActivite.length > 0

  return (
    <div className={`grid grid-cols-1 gap-3 sm:grid-cols-2 ${avecColonneSansActivite ? 'lg:grid-cols-6' : 'lg:grid-cols-5'}`}>
      {avecColonneSansActivite && (
        <div className="flex flex-col gap-2 rounded-[var(--radius-carte)] border border-dashed border-border bg-nested p-3">
          <p className="text-xs font-semibold tracking-wide text-muted uppercase">
            Sans activité <span className="chiffres">({projetsSansActivite.length})</span>
          </p>
          <div className="flex max-h-[32rem] flex-col gap-2 overflow-y-auto">
            {projetsSansActivite.map((p) => (
              <Link
                key={p.uuid}
                to={`/initiatives/${p.uuid}`}
                className="rounded-[var(--radius-controle)] border border-dashed border-border bg-surface p-3 text-sm transition-colors hover:border-accent"
              >
                <p className="font-medium">{p.libelle}</p>
                <p className="mt-1 text-xs text-muted">Ouvrir le projet pour ajouter une activité</p>
              </Link>
            ))}
          </div>
        </div>
      )}

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
