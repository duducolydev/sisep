import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AdminOnglets } from '@/components/AdminOnglets'
import { Bouton } from '@/components/Bouton'
import { GabaritEtapesModale } from '@/components/GabaritEtapesModale'
import { IconeBouton, IconeModifier, IconePlus, IconeSupprimer } from '@/components/Icones'
import { Squelette } from '@/components/Squelette'
import { useToast } from '@/components/ToastProvider'
import { ApiError } from '@/lib/api-client'
import { gabaritsEtapesQueryOptions, modifierGabaritEtapes, supprimerGabaritEtapes, type GabaritEtapes } from '@/lib/etapes'
import { queryClient } from '@/lib/query-client'
import { usePeut } from '@/lib/use-permissions'
import { useConfirmer } from '@/lib/confirmation'

/** Référentiel des gabarits d'étapes (§ 6 du CR du 22/09/2026) — la méthode de suivi du projet se normalise ici, pas activité par activité. */
export function AdminGabaritsEtapes() {
  const confirmer = useConfirmer()
  const { data: gabarits, isLoading, isError } = useQuery(gabaritsEtapesQueryOptions(true))
  const peutGerer = usePeut('admin.parametres')
  const afficherToast = useToast()

  const [modale, setModale] = useState<{ gabarit?: GabaritEtapes } | null>(null)
  const [actionEnCours, setActionEnCours] = useState<string | null>(null)
  const [erreur, setErreur] = useState<string | null>(null)

  async function executer(uuid: string, action: () => Promise<unknown>, message: string) {
    setErreur(null)
    setActionEnCours(uuid)
    try {
      await action()
      await queryClient.invalidateQueries({ queryKey: ['gabarits-etapes'] })
      afficherToast(message)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setActionEnCours(null)
    }
  }

  return (
    <div>
      <p className="font-titre text-eyebrow uppercase tracking-wide">Backoffice</p>
      <h1 className="text-2xl">Administration</h1>
      <p className="mb-6 text-muted">Référentiels, parties prenantes, rôles et permissions, journal des actions.</p>

      <AdminOnglets />

      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Modèles d'étapes</h2>
          <p className="mt-1 max-w-2xl text-sm text-muted">
            Découpages types appliqués aux activités et à leurs lignes. Appliquer un modèle copie ses étapes : le modifier ensuite ne
            touche pas les activités déjà structurées.
          </p>
        </div>
        {peutGerer && (
          <Bouton taille="normale" icone={<IconePlus />} onClick={() => setModale({})}>
            Nouveau modèle
          </Bouton>
        )}
      </div>

      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      {isLoading && <Squelette lignes={3} />}
      {isError && <p className="text-bad">Impossible de charger les modèles d'étapes. Réessayez.</p>}

      {gabarits && gabarits.donnees.length === 0 && (
        <p className="text-muted">Aucun modèle d'étapes pour le moment.</p>
      )}

      {gabarits && gabarits.donnees.length > 0 && (
        <ul className="flex flex-col gap-3">
          {gabarits.donnees.map((g) => (
            <li key={g.uuid} className="rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold">{g.libelle}</span>
                    <span className="chiffres text-xs text-muted">{g.etapes.length} étape(s)</span>
                    {!g.actif && (
                      <span className="rounded-full bg-nested px-2 py-0.5 text-xs text-muted">Retiré du catalogue</span>
                    )}
                  </p>
                  {g.description && <p className="mt-1 text-sm text-muted">{g.description}</p>}
                </div>

                {peutGerer && (
                  <div className="flex shrink-0 items-center gap-1">
                    <IconeBouton libelle="Modifier le modèle" onClick={() => setModale({ gabarit: g })}>
                      <IconeModifier />
                    </IconeBouton>
                    <Bouton
                      variante="discret"
                      taille="mini"
                      disabled={actionEnCours === g.uuid}
                      onClick={() =>
                        void executer(
                          g.uuid,
                          () => modifierGabaritEtapes(g.uuid, { actif: !g.actif }),
                          g.actif ? 'Modèle retiré du catalogue.' : 'Modèle remis au catalogue.',
                        )
                      }
                    >
                      {g.actif ? 'Retirer du catalogue' : 'Remettre au catalogue'}
                    </Bouton>
                    <IconeBouton
                      libelle="Supprimer le modèle"
                      disabled={actionEnCours === g.uuid}
                      onClick={async () => {
                        if (await confirmer({ titre: 'Supprimer le modèle', message: `Le modèle « ${g.libelle} » sera supprimé. Les étapes déjà créées à partir de lui sur les activités restent en place.`, action: 'Supprimer' })) {
                          void executer(g.uuid, () => supprimerGabaritEtapes(g.uuid), 'Modèle supprimé.')
                        }
                      }}
                      classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                    >
                      <IconeSupprimer />
                    </IconeBouton>
                  </div>
                )}
              </div>

              <ol className="mt-2 flex flex-wrap items-center gap-1.5 text-sm">
                {g.etapes.map((e) => (
                  <li key={e.uuid} className="rounded-full bg-nested px-2.5 py-1">
                    <span className="chiffres mr-1.5 text-xs text-muted">{e.ordre}</span>
                    {e.libelle}
                  </li>
                ))}
              </ol>
            </li>
          ))}
        </ul>
      )}

      {modale && <GabaritEtapesModale gabarit={modale.gabarit} onFermer={() => setModale(null)} />}
    </div>
  )
}
