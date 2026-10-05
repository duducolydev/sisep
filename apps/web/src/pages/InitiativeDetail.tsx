import { useState } from 'react'
import { useParams, Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { initiativeQueryOptions, definirTacheTerminee } from '@/lib/initiatives'
import { formaterDate, formaterTaux } from '@/lib/format'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { usePeut, useUtilisateurCourant } from '@/lib/use-permissions'
import { PastilleStatutInitiative } from '@/components/PastilleStatutInitiative'
import { Bascule } from '@/components/Bascule'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { AjouterActiviteInitiativeModale } from '@/components/AjouterActiviteInitiativeModale'
import { AjouterTacheInitiativeModale } from '@/components/AjouterTacheInitiativeModale'
import { ObservationsInitiative } from '@/components/ObservationsInitiative'
import { DocumentsInitiative } from '@/components/DocumentsInitiative'
import { IconePlus, IconeDeplierTout, IconeReplierTout } from '@/components/Icones'
import { Squelette } from '@/components/Squelette'

const BOUTON_AJOUT_COMPACT =
  'flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-accent-d'

export function InitiativeDetail() {
  const { uuid } = useParams<{ uuid: string }>()
  const { data: initiative, isLoading, isError } = useQuery(initiativeQueryOptions(uuid))
  const { data: utilisateur } = useUtilisateurCourant()
  const peutGerer = usePeut('initiatives.gerer')

  const [erreur, setErreur] = useState<string | null>(null)
  const [actionEnCours, setActionEnCours] = useState<string | null>(null)
  const [modaleActivite, setModaleActivite] = useState(false)
  const [activiteTache, setActiviteTache] = useState<string | null>(null)
  const [tacheDetailsOuverte, setTacheDetailsOuverte] = useState<string | null>(null)

  if (isLoading) return <Squelette lignes={6} />
  if (isError || !initiative) {
    return (
      <div>
        <p className="text-bad">Projet introuvable.</p>
        <Link to="/initiatives" className="text-accent">
          Retour aux projets
        </Link>
      </div>
    )
  }

  async function basculerTache(tacheUuid: string, terminee: boolean) {
    setErreur(null)
    setActionEnCours(tacheUuid)
    try {
      await definirTacheTerminee(tacheUuid, terminee)
      await queryClient.invalidateQueries({ queryKey: ['initiatives'] })
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setActionEnCours(null)
    }
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Link to="/initiatives" className="text-sm text-accent">
          ← Projets
        </Link>
        <p className="mt-2 text-[10px] font-semibold uppercase tracking-[.14em] text-eyebrow">
          {initiative.lienPtba ? `${initiative.lienPtba.code} — ${initiative.lienPtba.libelle}` : 'Indépendant du PTBA'}
        </p>
        <h1 className="text-2xl">{initiative.libelle}</h1>
        {initiative.description && <p className="text-muted">{initiative.description}</p>}
        {initiative.lienPtba && (
          <p className="mt-2 text-sm text-muted">
            Taux d'exécution budgétaire PTBA : <span className="chiffres">{formaterTaux(initiative.lienPtba.tauxExecutionPtba)}</span>
          </p>
        )}
      </div>

      {erreur && (
        <div role="alert" className="rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <section>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-semibold">Activités</h2>
          {peutGerer && (
            <button type="button" onClick={() => setModaleActivite(true)} className={BOUTON_AJOUT_COMPACT}>
              <IconePlus />
              Ajouter une activité
            </button>
          )}
        </div>

        {initiative.activites.length === 0 ? (
          <p className="text-muted">Aucune activité pour le moment.</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {initiative.activites.map((a) => {
              const estResponsable = a.responsables.some((r) => r.uuid === utilisateur?.uuid)
              const peutGererTaches = peutGerer || estResponsable

              return (
                <li key={a.uuid} className="flex flex-col gap-3 rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <p className="font-semibold">{a.libelle}</p>
                      <p className="text-sm text-muted">
                        {a.dateDebut ? formaterDate(a.dateDebut) : '—'} → {a.echeance ? formaterDate(a.echeance) : 'Sans échéance'}
                        {a.responsables.length > 0 && <> · {a.responsables.map((r) => r.nomComplet).join(', ')}</>}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <PastilleStatutInitiative statut={a.statut} />
                      {a.dateReelle && <span className="text-xs text-muted">Terminée le {formaterDate(a.dateReelle)}</span>}
                    </div>
                  </div>

                  <BarreAtteinte taux={a.avancement} />

                  <div>
                    <div className="mb-1 flex items-center justify-between">
                      <p className="text-xs font-semibold uppercase tracking-wide text-muted">Tâches</p>
                      {peutGererTaches && (
                        <button type="button" onClick={() => setActiviteTache(a.uuid)} className={BOUTON_AJOUT_COMPACT} title="Ajouter une tâche">
                          <IconePlus />
                        </button>
                      )}
                    </div>
                    {a.taches.length === 0 ? (
                      <p className="text-sm text-muted">Aucune tâche.</p>
                    ) : (
                      <ul className="flex flex-col gap-1">
                        {a.taches.map((t) => {
                          const detailsOuverts = tacheDetailsOuverte === t.uuid
                          const estResponsableTache = t.responsable?.uuid === utilisateur?.uuid
                          const peutGererCetteTache = peutGererTaches || estResponsableTache

                          return (
                            <li key={t.uuid} className="text-sm">
                              <div
                                role="button"
                                tabIndex={0}
                                onClick={() => setTacheDetailsOuverte(detailsOuverts ? null : t.uuid)}
                                onKeyDown={(e) => 'Enter' === e.key && setTacheDetailsOuverte(detailsOuverts ? null : t.uuid)}
                                className="-mx-1 flex cursor-pointer items-center justify-between gap-2 rounded-[var(--radius-controle)] px-1 py-1 transition-colors hover:bg-nested"
                              >
                                <span className="min-w-0 truncate">
                                  {t.libelle}
                                  {t.responsable && <span className="text-muted"> — {t.responsable.nomComplet}</span>}
                                </span>
                                <div className="flex shrink-0 items-center gap-2">
                                  {t.dateRealisation && (
                                    <span className="text-xs text-muted">le {formaterDate(t.dateRealisation)}</span>
                                  )}
                                  {peutGererCetteTache && (
                                    <span onClick={(e) => e.stopPropagation()}>
                                      <Bascule
                                        checked={null !== t.dateRealisation}
                                        disabled={actionEnCours === t.uuid}
                                        onCheckedChange={(coche) => void basculerTache(t.uuid, coche)}
                                        libelle="Terminée"
                                      />
                                    </span>
                                  )}
                                  <PastilleStatutInitiative statut={t.statut} />
                                  <span className="text-muted">{detailsOuverts ? <IconeReplierTout /> : <IconeDeplierTout />}</span>
                                </div>
                              </div>

                              {detailsOuverts && (
                                <div className="mt-2 flex flex-col gap-3 rounded-[var(--radius-controle)] bg-accent-clair-bg p-3">
                                  <ObservationsInitiative
                                    entiteType="tache_initiative"
                                    entiteUuid={t.uuid}
                                    notes={t.notes}
                                    peutAjouter={peutGererCetteTache}
                                  />
                                  <DocumentsInitiative
                                    entiteType="tache_initiative"
                                    entiteUuid={t.uuid}
                                    piecesJointes={t.piecesJointes}
                                    peutAjouter={peutGererCetteTache}
                                  />
                                </div>
                              )}
                            </li>
                          )
                        })}
                      </ul>
                    )}
                  </div>

                  <div className="flex flex-col gap-3 rounded-[var(--radius-controle)] bg-accent-clair-bg p-3">
                    <ObservationsInitiative
                      entiteType="initiative_activite"
                      entiteUuid={a.uuid}
                      notes={a.notes}
                      peutAjouter={peutGererTaches}
                    />
                    <DocumentsInitiative
                      entiteType="initiative_activite"
                      entiteUuid={a.uuid}
                      piecesJointes={a.piecesJointes}
                      peutAjouter={peutGererTaches}
                    />
                  </div>

                  {activiteTache === a.uuid && (
                    <AjouterTacheInitiativeModale initiativeActiviteUuid={a.uuid} onFermer={() => setActiviteTache(null)} />
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </section>

      {modaleActivite && <AjouterActiviteInitiativeModale initiativeUuid={initiative.uuid} onFermer={() => setModaleActivite(false)} />}
    </div>
  )
}
