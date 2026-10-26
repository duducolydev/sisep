import { useState } from 'react'
import { useParams, Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { activiteQueryOptions, modifierStatutActivite, type SousActiviteDetail } from '@/lib/composantes'
import { formaterDate, formaterFcfa, formaterTaux } from '@/lib/format'
import {
  annulerDepense,
  annulerEngagement,
  LIBELLES_NATURE_ENGAGEMENT,
  validerDepense,
  validerEngagement,
  type Dotation,
  type Engagement,
} from '@/lib/budget'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { usePeut, usePeutSur, usePeutSurStructure } from '@/lib/use-permissions'
import { BarreExecution } from '@/components/BarreExecution'
import { Pastille } from '@/components/Pastille'
import { SaisirDotationModale } from '@/components/SaisirDotationModale'
import { SaisirEngagementModale } from '@/components/SaisirEngagementModale'
import { SaisirDepenseModale } from '@/components/SaisirDepenseModale'
import { CreerSousActiviteModale } from '@/components/CreerSousActiviteModale'
import { ValiderClotureBouton } from '@/components/ValiderClotureBouton'
import { Squelette } from '@/components/Squelette'
import { EtapesActivite } from '@/components/EtapesActivite'
import { Bouton } from '@/components/Bouton'
import { IconeDotation, IconeEngagement, IconeDepense, IconeValider, IconeAnnuler, IconePlus } from '@/components/Icones'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'

type CibleBudget = { type: 'activite' | 'sous_activite'; uuid: string }
type Modale =
  | { type: 'dotation'; cible: CibleBudget }
  | { type: 'engagement'; cible: CibleBudget }
  | { type: 'depense'; engagementUuid: string }
  | { type: 'sousActivite' }
  | null

export function ActiviteDetail() {
  const { uuid } = useParams<{ uuid: string }>()
  const { data: activite, isLoading, isError } = useQuery(activiteQueryOptions(uuid))

  const [modale, setModale] = useState<Modale>(null)
  const [actionEnCours, setActionEnCours] = useState<string | null>(null)
  const [erreurAction, setErreurAction] = useState<string | null>(null)

  // Structurer relève de l'UGP, renseigner relève du terrain (§ 6 du CR du 22/09/2026).
  const peutStructurerEtapes = usePeutSur('activites.creer', activite?.structureResponsable.uuid ?? null)
  const peutRenseignerEtapes = usePeutSur('activites.modifier', activite?.structureResponsable.uuid ?? null)
  // Une ligne peut relever d'une autre direction que l'activité : c'est sa
  // structure à elle qui décide qui la structure et la renseigne.
  const peutStructurerSur = usePeutSurStructure('activites.creer')
  const peutRenseignerSur = usePeutSurStructure('activites.modifier')
  const peutSaisirEngagement = usePeut('budget.engagement.saisir')
  const peutValiderEngagement = usePeut('budget.engagement.valider')
  const peutSaisirDepense = usePeut('budget.depense.saisir')
  const peutValiderDepense = usePeut('budget.depense.valider')
  const peutExporter = usePeut('exports.generer')

  if (isLoading) return <Squelette lignes={6} />
  if (isError || !activite) {
    return (
      <div>
        <p className="text-bad">Activité introuvable, ou hors de votre périmètre.</p>
        <Link to="/composantes" className="text-accent">
          Retour aux composantes
        </Link>
      </div>
    )
  }

  const exerciceCourant = new Date().getFullYear()
  // etapesTotal compte aussi les étapes des lignes : la différence donne les étapes propres.
  const etapesPropres = activite.etapesTotal - activite.sousActivites.reduce((total, sa) => total + sa.etapesTotal, 0)

  async function executerAction(cle: string, action: () => Promise<unknown>) {
    setErreurAction(null)
    setActionEnCours(cle)
    try {
      await action()
      await queryClient.invalidateQueries({ queryKey: ['activite', uuid] })
      await queryClient.invalidateQueries({ queryKey: ['budget'] })
    } catch (e) {
      setErreurAction(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setActionEnCours(null)
    }
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Link to="/composantes" className="text-sm text-accent">
          ← Composantes
        </Link>
        <div className="mt-2 flex items-center gap-3">
          <span className="chiffres text-muted">{activite.code}</span>
          <Pastille statut={activite.statut} />
          {/* La clôture se juge sur la progression déduite des étapes : l'ancien
              avancement, alimenté par les réalisations, ne l'est plus. */}
          <ValiderClotureBouton
            statut={activite.statut}
            avancement={activite.progression === null ? null : Math.round(activite.progression * 100)}
            structureResponsableUuid={activite.structureResponsable.uuid}
            onValider={async () => {
              await modifierStatutActivite(activite.uuid, 'terminee')
              await queryClient.invalidateQueries({ queryKey: ['activite', uuid] })
              await queryClient.invalidateQueries({ queryKey: ['arbre'] })
            }}
          />
        </div>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <h1 className="text-2xl">{activite.libelle}</h1>
          {peutExporter && <BoutonExporter href={urlExportDonnees(`activites/${activite.uuid}`)} libelle="Exporter" />}
        </div>
        {activite.description && <p className="text-muted">{activite.description}</p>}
        <p className="mt-2 text-sm text-muted">
          {activite.sousComposante.code} — {activite.sousComposante.libelle} · Responsable :{' '}
          {activite.structureResponsable.sigle}
        </p>

        {/* Progression déduite des étapes, jamais stockée. Null = activité pas encore structurée, ce qui ne se confond pas avec 0 %. */}
        {activite.progression !== null && (
          <div className="mt-3 flex flex-wrap items-center gap-3">
            <div className="h-2 w-40 overflow-hidden rounded-[var(--radius-barre)] bg-track">
              <div
                className={activite.progression === 1 ? 'h-full bg-success' : 'h-full bg-accent'}
                style={{ width: `${Math.round(activite.progression * 100)}%` }}
              />
            </div>
            <span className="chiffres text-sm">{formaterTaux(activite.progression)}</span>
            <span className="chiffres text-sm text-muted">
              {activite.etapesAchevees} / {activite.etapesTotal} étapes achevées
            </span>
          </div>
        )}
      </div>

      {erreurAction && (
        <div role="alert" className="rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreurAction}
        </div>
      )}

      <section>
        <div className="mb-2 flex items-center justify-between">
          <h2 className="text-lg font-semibold">Exécution budgétaire</h2>
          <div className="flex gap-3">
            {peutSaisirEngagement && (
              <Bouton
                variante="secondaire"
                icone={<IconeDotation />}
                onClick={() => setModale({ type: 'dotation', cible: { type: 'activite', uuid: activite.uuid } })}
              >
                Notifier une dotation
              </Bouton>
            )}
            {peutSaisirEngagement && (
              <Bouton
                icone={<IconeEngagement />}
                onClick={() => setModale({ type: 'engagement', cible: { type: 'activite', uuid: activite.uuid } })}
              >
                Saisir un engagement
              </Bouton>
            )}
          </div>
        </div>
        <div className="max-w-md">
          <BarreExecution notifie={activite.notifie} engage={activite.engage} depense={activite.depense} />
        </div>
        <dl className="mt-3 grid grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-muted">Notifié</dt>
            <dd className="chiffres">{activite.notifie === null ? '—' : formaterFcfa(activite.notifie)}</dd>
          </div>
          <div>
            <dt className="text-muted">Engagé</dt>
            <dd className="chiffres">{activite.engage === null ? '—' : formaterFcfa(activite.engage)}</dd>
          </div>
          <div>
            <dt className="text-muted">Dépensé</dt>
            <dd className="chiffres">{activite.depense === null ? '—' : formaterFcfa(activite.depense)}</dd>
          </div>
        </dl>
        {activite.dotations.length > 0 && (
          <ul className="mt-3 flex flex-col gap-1 text-sm">
            {activite.dotations.map((d) => (
              <LigneDotation key={d.uuid} dotation={d} />
            ))}
          </ul>
        )}
        {activite.engagements.length > 0 && (
          <div className="mt-4">
            <ListeEngagements
              engagements={activite.engagements}
              peutValiderEngagement={peutValiderEngagement}
              peutSaisirDepense={peutSaisirDepense}
              peutValiderDepense={peutValiderDepense}
              actionEnCours={actionEnCours}
              executerAction={executerAction}
              onSaisirDepense={(engagementUuid) => setModale({ type: 'depense', engagementUuid })}
            />
          </div>
        )}
      </section>

      {/* Avec des lignes, l'avancement se suit ligne par ligne (retours des
          RSE) : les étapes propres de l'activité ne s'affichent plus que si
          elle en porte déjà ou n'a aucune ligne. */}
      {(activite.sousActivites.length === 0 || etapesPropres > 0) && (
        <EtapesActivite
          activiteUuid={activite.uuid}
          titre={activite.sousActivites.length === 0 ? 'Étapes' : "Étapes propres de l'activité"}
          peutStructurer={peutStructurerEtapes}
          peutRenseigner={peutRenseignerEtapes}
        />
      )}

      <section>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="text-lg font-semibold">Activités de {activite.structureResponsable.sigle}</h2>
            <p className="text-sm text-muted">Lignes du PTBA, chacune avec son budget et ses étapes</p>
          </div>
          {/* Découpage réservé à l'UGP : activites.creer, la permission que le serveur exige. */}
          {peutStructurerEtapes && (
            <Bouton variante="discret" taille="mini" icone={<IconePlus />} onClick={() => setModale({ type: 'sousActivite' })}>
              Ajouter une ligne d'activité
            </Bouton>
          )}
        </div>
        {activite.sousActivites && activite.sousActivites.length > 0 ? (
          <ul className="flex flex-col gap-3">
            {activite.sousActivites.map((sa) => (
              <li
                key={sa.uuid}
                className="flex flex-col gap-3 rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]"
              >
                <div className="flex items-center justify-between">
                  <div>
                    <p>{sa.libelle}</p>
                    <p className="text-sm text-muted">
                      {sa.echeance ? `Échéance : ${formaterDate(sa.echeance)}` : 'Sans échéance'}
                      {sa.structureResponsable && sa.structureResponsable.uuid !== activite.structureResponsable.uuid && (
                        <> · Ligne confiée à {sa.structureResponsable.sigle}</>
                      )}
                    </p>
                  </div>
                  <div className="flex items-center gap-3">
                    <Pastille statut={sa.statut} />
                  </div>
                </div>

                <EtapesLigne
                  activiteUuid={activite.uuid}
                  ligne={sa}
                  peutStructurer={peutStructurerSur(sa.structureResponsable?.uuid ?? activite.structureResponsable.uuid)}
                  peutRenseigner={peutRenseignerSur(sa.structureResponsable?.uuid ?? activite.structureResponsable.uuid)}
                />

                <div className="flex items-center justify-between">
                  <div className="max-w-xs flex-1">
                    <BarreExecution notifie={sa.notifie} engage={sa.engage} depense={sa.depense} />
                  </div>
                  {peutSaisirEngagement && (
                    <div className="flex shrink-0 gap-2">
                      <Bouton
                        variante="discret"
                        taille="mini"
                        icone={<IconeDotation />}
                        onClick={() => setModale({ type: 'dotation', cible: { type: 'sous_activite', uuid: sa.uuid } })}
                      >
                        Notifier une dotation
                      </Bouton>
                      <Bouton
                        variante="discret"
                        taille="mini"
                        icone={<IconeEngagement />}
                        onClick={() => setModale({ type: 'engagement', cible: { type: 'sous_activite', uuid: sa.uuid } })}
                      >
                        Saisir un engagement
                      </Bouton>
                    </div>
                  )}
                </div>

                {sa.dotations.length > 0 && (
                  <ul className="flex flex-col gap-1 text-sm">
                    {sa.dotations.map((d) => (
                      <LigneDotation key={d.uuid} dotation={d} />
                    ))}
                  </ul>
                )}

                {sa.engagements.length > 0 && (
                  <ListeEngagements
                    engagements={sa.engagements}
                    peutValiderEngagement={peutValiderEngagement}
                    peutSaisirDepense={peutSaisirDepense}
                    peutValiderDepense={peutValiderDepense}
                    actionEnCours={actionEnCours}
                    executerAction={executerAction}
                    onSaisirDepense={(engagementUuid) => setModale({ type: 'depense', engagementUuid })}
                  />
                )}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-muted">Aucune sous-activité.</p>
        )}
      </section>

      {modale?.type === 'dotation' && (
        <SaisirDotationModale
          cible={modale.cible}
          activiteUuid={activite.uuid}
          exerciceParDefaut={exerciceCourant}
          onFermer={() => setModale(null)}
        />
      )}
      {modale?.type === 'engagement' && (
        <SaisirEngagementModale
          cible={modale.cible}
          activiteUuid={activite.uuid}
          exerciceParDefaut={exerciceCourant}
          onFermer={() => setModale(null)}
        />
      )}
      {modale?.type === 'depense' && (
        <SaisirDepenseModale engagementUuid={modale.engagementUuid} activiteUuid={activite.uuid} onFermer={() => setModale(null)} />
      )}
      {modale?.type === 'sousActivite' && (
        <CreerSousActiviteModale
          activiteUuid={activite.uuid}
          ordreSuivant={(activite.sousActivites?.length ?? 0) + 1}
          onFermer={() => setModale(null)}
        />
      )}
    </div>
  )
}

/**
 * Étapes d'une ligne du PTBA (retours des RSE : RNOCB et son recrutement,
 * sa formation des enquêteurs, sa phase pilote…), repliées par défaut pour
 * que la liste des lignes reste lisible.
 */
function EtapesLigne({
  activiteUuid,
  ligne,
  peutStructurer,
  peutRenseigner,
}: {
  activiteUuid: string
  ligne: SousActiviteDetail
  peutStructurer: boolean
  peutRenseigner: boolean
}) {
  const [ouvert, setOuvert] = useState(false)

  return (
    <div className="rounded-[var(--radius-controle)] bg-nested px-3 py-2">
      <button
        type="button"
        onClick={() => setOuvert((o) => !o)}
        aria-expanded={ouvert}
        className="flex w-full flex-wrap items-center gap-3 text-left text-sm"
      >
        <span aria-hidden="true" className="text-muted">
          {ouvert ? '▾' : '▸'}
        </span>
        <span className="font-semibold">Étapes</span>
        {ligne.progression !== null ? (
          <>
            <span className="h-1.5 w-28 overflow-hidden rounded-[var(--radius-barre)] bg-track">
              <span
                className={`block h-full ${ligne.progression === 1 ? 'bg-success' : 'bg-accent'}`}
                style={{ width: `${Math.round(ligne.progression * 100)}%` }}
              />
            </span>
            <span className="chiffres">{formaterTaux(ligne.progression)}</span>
            <span className="chiffres text-muted">
              {ligne.etapesAchevees} / {ligne.etapesTotal} achevées
            </span>
          </>
        ) : (
          <span className="text-muted">non structurée</span>
        )}
      </button>
      {ouvert && (
        <div className="mt-3">
          <EtapesActivite
            activiteUuid={activiteUuid}
            sousActiviteUuid={ligne.uuid}
            peutStructurer={peutStructurer}
            peutRenseigner={peutRenseigner}
          />
        </div>
      )}
    </div>
  )
}

/** Ligne compacte affichant une dotation, avec sa révision COPIL si renseignée (§ 5.1, alignement PTBA). */
function LigneDotation({ dotation }: { dotation: Dotation }) {
  return (
    <li className="flex flex-col gap-0.5">
      <div className="flex items-center justify-between">
        <span className="text-muted">Dotation {dotation.exercice}</span>
        <span className="chiffres">{formaterFcfa(dotation.montant)}</span>
      </div>
      {dotation.montantValideCopil !== null && (
        <div className="flex items-center justify-between text-xs text-muted">
          <span>Validé COPIL{dotation.commentaireRevision ? ` — ${dotation.commentaireRevision}` : ''}</span>
          <span className="chiffres">
            {formaterFcfa(dotation.montantValideCopil)}
            {dotation.ecartCopil !== null && dotation.ecartCopil !== 0 && (
              <> ({dotation.ecartCopil > 0 ? '+' : ''}{formaterFcfa(dotation.ecartCopil)})</>
            )}
          </span>
        </div>
      )}
    </li>
  )
}

interface ListeEngagementsProps {
  engagements: Engagement[]
  peutValiderEngagement: boolean
  peutSaisirDepense: boolean
  peutValiderDepense: boolean
  actionEnCours: string | null
  executerAction: (cle: string, action: () => Promise<unknown>) => Promise<void>
  onSaisirDepense: (engagementUuid: string) => void
}

function ListeEngagements({
  engagements,
  peutValiderEngagement,
  peutSaisirDepense,
  peutValiderDepense,
  actionEnCours,
  executerAction,
  onSaisirDepense,
}: ListeEngagementsProps) {
  return (
    <ul className="flex flex-col gap-3">
      {engagements.map((e) => (
        <li key={e.uuid} className="rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
          <div className="flex items-center justify-between">
            <span>
              {e.referenceMarche ?? 'Sans référence'} · {LIBELLES_NATURE_ENGAGEMENT[e.nature] ?? e.nature}
            </span>
            <span className="chiffres">{formaterFcfa(e.montant)}</span>
          </div>
          <div className="mt-1 flex items-center justify-between">
            <p className="text-sm text-muted">
              Engagé le {formaterDate(e.dateEngagement)} · <Pastille statut={e.statut} />
            </p>
            <div className="flex gap-1.5">
              {e.statut === 'brouillon' && peutValiderEngagement && (
                <Bouton
                  variante="discret"
                  taille="mini"
                  icone={<IconeValider />}
                  disabled={actionEnCours === `valider-${e.uuid}`}
                  onClick={() => executerAction(`valider-${e.uuid}`, () => validerEngagement(e.uuid))}
                >
                  Valider
                </Bouton>
              )}
              {e.statut === 'brouillon' && peutValiderEngagement && (
                <Bouton
                  variante="danger"
                  taille="mini"
                  icone={<IconeAnnuler />}
                  disabled={actionEnCours === `annuler-${e.uuid}`}
                  onClick={() => executerAction(`annuler-${e.uuid}`, () => annulerEngagement(e.uuid))}
                >
                  Annuler
                </Bouton>
              )}
              {e.statut === 'valide' && peutSaisirDepense && (
                <Bouton variante="discret" taille="mini" icone={<IconeDepense />} onClick={() => onSaisirDepense(e.uuid)}>
                  Saisir une dépense
                </Bouton>
              )}
            </div>
          </div>
          {e.depenses.length > 0 && (
            <ul className="mt-2 ml-4 flex flex-col gap-1 border-l border-border pl-3">
              {e.depenses.map((d) => (
                <li key={d.uuid} className="flex items-center justify-between text-sm">
                  <span>
                    {d.referencePaiement ?? 'Dépense'} — {formaterDate(d.dateDepense)} · <Pastille statut={d.statut} />
                  </span>
                  <span className="flex items-center gap-2">
                    <span className="chiffres">{formaterFcfa(d.montant)}</span>
                    {d.statut === 'brouillon' && peutValiderDepense && (
                      <Bouton
                        variante="discret"
                        taille="mini"
                        icone={<IconeValider />}
                        disabled={actionEnCours === `valider-dep-${d.uuid}`}
                        onClick={() => executerAction(`valider-dep-${d.uuid}`, () => validerDepense(d.uuid))}
                      >
                        Valider
                      </Bouton>
                    )}
                    {d.statut === 'brouillon' && peutValiderDepense && (
                      <Bouton
                        variante="discret"
                        taille="mini"
                        icone={<IconeAnnuler />}
                        disabled={actionEnCours === `annuler-dep-${d.uuid}`}
                        onClick={() => executerAction(`annuler-dep-${d.uuid}`, () => annulerDepense(d.uuid))}
                      >
                        Annuler
                      </Bouton>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </li>
      ))}
    </ul>
  )
}
