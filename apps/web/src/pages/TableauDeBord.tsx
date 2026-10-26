import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router'
import { projetCourantQueryOptions, arbreQueryOptions, progressionProjet } from '@/lib/composantes'
import { decaissementQueryOptions } from '@/lib/budget'
import { indicateursQueryOptions } from '@/lib/indicateurs'
import { syntheseQueryOptions, alertesQueryOptions, activiteRecenteQueryOptions } from '@/lib/tableau-de-bord'
import { usePeriodeFiltre } from '@/lib/periode'
import { formaterMds, formaterTaux, formaterDate } from '@/lib/format'
import { ArbreExecution } from '@/components/ArbreExecution'
import { BarresDecaissement } from '@/components/BarresDecaissement'
import { PastilleVigilance } from '@/components/PastilleVigilance'
import { SelecteurExercice } from '@/components/SelecteurExercice'
import { SelecteurPeriode } from '@/components/SelecteurPeriode'
import { IconeVoir } from '@/components/Icones'
import { usePeut, useUtilisateurCourant } from '@/lib/use-permissions'
import { rapportsQueryOptions } from '@/lib/parties-prenantes'
import { AtteinteGlobaleIndicateurs } from '@/components/AtteinteGlobaleIndicateurs'
import { PhysiqueFinancierParComposante } from '@/components/tableau-de-bord/PhysiqueFinancierParComposante'
import { EngagementsParNature } from '@/components/tableau-de-bord/EngagementsParNature'
import { ExecutionParStructure } from '@/components/tableau-de-bord/ExecutionParStructure'
import { RepartitionStatutsActivites } from '@/components/tableau-de-bord/RepartitionStatutsActivites'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'

const CLASSES_EVENEMENT: Record<string, string> = {
  creation: 'bg-warn',
  modification: 'bg-ok',
}

export function TableauDeBord() {
  const [searchParams] = useSearchParams()
  const exercice = searchParams.get('exercice') ? Number(searchParams.get('exercice')) : undefined
  const { correspond: correspondAPeriode } = usePeriodeFiltre()

  const { data: projet } = useQuery(projetCourantQueryOptions())
  const { data: synthese, isLoading: chargeSynthese } = useQuery(syntheseQueryOptions(projet?.uuid, exercice))
  const { data: arbre } = useQuery(arbreQueryOptions(projet?.uuid, 3, exercice))
  const { data: decaissement } = useQuery(decaissementQueryOptions(projet?.uuid, exercice))
  const { data: alertes } = useQuery(alertesQueryOptions(projet?.uuid, exercice))
  // Module réservé par défaut à l'équipe PHASAOC (§ décision), sauf
  // invitation ponctuelle — cf. Layout.tsx pour le même hint.
  const voitIndicateurs = usePeut('indicateurs.consulter')
  const peutExporter = usePeut('exports.generer')
  const { data: indicateurs } = useQuery({ ...indicateursQueryOptions(), enabled: voitIndicateurs })
  const { data: activiteRecente } = useQuery(activiteRecenteQueryOptions(projet?.uuid))
  // Reporting des structures : réservé à l'équipe du projet (parties prenantes).
  const { data: moi } = useUtilisateurCourant()
  const voitReporting = moi?.permissionsPortee['parties_prenantes.consulter'] === null
  const { data: rapports } = useQuery({
    ...rapportsQueryOptions({ exercice: exercice ?? synthese?.exercice }),
    enabled: voitReporting && synthese !== undefined,
  })

  // Indicateurs de pilotage — tous dérivés de données déjà servies, jamais
  // stockés ; un taux sans dénominateur reste « — ».
  const activites = (arbre?.composantes ?? []).flatMap((c) => (c.sousComposantes ?? []).flatMap((sc) => sc.activites ?? []))
  const activitesTerminees = activites.filter((a) => a.statut === 'terminee').length
  const physique = progressionProjet(arbre?.composantes ?? [])
  const enRetard = (alertes?.alertes ?? []).filter((a) => a.vigilance === 'en_retard').length
  const risqueEleve = (alertes?.alertes ?? []).filter((a) => a.vigilance === 'risque_eleve').length
  const indicateursAvecTaux = (indicateurs?.donnees ?? []).filter((i) => i.tauxAtteinte !== null)
  const atteinteMoyenne =
    indicateursAvecTaux.length > 0 ? indicateursAvecTaux.reduce((s, i) => s + (i.tauxAtteinte ?? 0), 0) / indicateursAvecTaux.length : null
  const valeursEnAttente = (indicateurs?.donnees ?? []).filter((i) => i.enAttente !== null).length
  const rapportsRecus = (rapports?.donnees ?? []).filter((r) => r.statut === 'transmis' || r.statut === 'valide').length
  const rapportsEnRetard = (rapports?.donnees ?? []).filter((r) => r.enRetard).length

  const alertesFiltrees = (alertes?.alertes ?? []).filter((a) => a.dateFinPrevue === null || correspondAPeriode(a.dateFinPrevue))
  const activiteRecenteFiltree = (activiteRecente?.evenements ?? []).filter((e) => correspondAPeriode(e.horodatage))

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-titre text-eyebrow uppercase tracking-wide">Tableau de bord</p>
          <h1 className="text-2xl">Synthèse du projet</h1>
          <p className="text-muted">
            Exercice {synthese?.exercice ?? '—'} · exécution financière et physique, décaissement, engagements, structures, cadre de résultats et reporting — § 2.1.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <SelecteurExercice projetUuid={projet?.uuid} />
          {peutExporter && (
            <BoutonExporter href={urlExportDonnees('tableau-de-bord', { exercice: searchParams.get('exercice') })} libelle="Exporter" />
          )}
          <SelecteurPeriode />
        </div>
      </div>

      <section className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
        <CarteKpi
          libelle="Budget notifié"
          montant={synthese?.notifie ?? null}
          sousTexte={arbre ? `${arbre.composantes.length} composantes` : undefined}
          couleur="accent"
          chargement={chargeSynthese}
        />
        <CarteKpi
          libelle="Engagements"
          montant={synthese?.engage ?? null}
          taux={synthese?.tauxEngagement ?? null}
          libelleTaux="du budget notifié"
          couleur="accent-clair"
          chargement={chargeSynthese}
        />
        <CarteKpi
          libelle="Dépenses exécutées"
          montant={synthese?.depense ?? null}
          taux={synthese?.tauxExecution ?? null}
          libelleTaux="du budget notifié"
          couleur="eyebrow"
          chargement={chargeSynthese}
        />
        <CarteKpi
          libelle="Solde disponible"
          montant={synthese?.disponible ?? null}
          taux={
            synthese && synthese.disponible !== null && synthese.notifie > 0 ? synthese.disponible / synthese.notifie : null
          }
          libelleTaux="du budget notifié"
          couleur="bad"
          chargement={chargeSynthese}
        />
      </section>

      <section aria-label="Indicateurs de pilotage" className="grid grid-cols-2 gap-3.5 md:grid-cols-3 xl:grid-cols-6">
        <CartePilotage
          libelle="Progression des étapes"
          valeur={formaterTaux(physique.progression === null ? null : physique.progression / 100)}
          detail={arbre ? `moyenne sur ${physique.suivies} activité${physique.suivies > 1 ? 's' : ''} suivie${physique.suivies > 1 ? 's' : ''}` : undefined}
        />
        <CartePilotage
          libelle="Décaissé / engagé"
          valeur={formaterTaux(synthese && synthese.engage > 0 ? synthese.depense / synthese.engage : null)}
          detail="part des engagements payée"
        />
        <CartePilotage
          libelle="Activités terminées"
          valeur={arbre ? `${activitesTerminees} / ${activites.length}` : '—'}
          detail={activites.length > 0 ? `${Math.round((activitesTerminees / activites.length) * 100)} % du PTBA` : undefined}
        />
        <CartePilotage
          libelle="Activités en alerte"
          valeur={alertes ? String(enRetard + risqueEleve) : '—'}
          detail={alertes ? `${enRetard} en retard · ${risqueEleve} risque élevé` : undefined}
          ton={enRetard + risqueEleve > 0 ? 'alerte' : 'neutre'}
        />
        {voitIndicateurs && (
          <CartePilotage
            libelle="Atteinte indicateurs"
            valeur={formaterTaux(atteinteMoyenne)}
            detail={`moyenne · ${valeursEnAttente} valeur${valeursEnAttente > 1 ? 's' : ''} à valider`}
            ton={valeursEnAttente > 0 ? 'attente' : 'neutre'}
          />
        )}
        {voitReporting && (
          <CartePilotage
            libelle="Rapports reçus"
            valeur={rapports ? `${rapportsRecus} / ${rapports.donnees.length}` : '—'}
            detail={rapports ? `trimestriels · ${rapportsEnRetard} en retard` : undefined}
            ton={rapportsEnRetard > 0 ? 'alerte' : 'neutre'}
          />
        )}
      </section>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-[1.6fr_1fr]">
        <div className="flex min-w-0 flex-col gap-8">
          <section>
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-lg font-semibold">Arbre d'exécution</h2>
              <Link
                to="/composantes"
                title="Tout voir — Composantes"
                aria-label="Tout voir — Composantes"
                className="rounded-[var(--radius-controle)] p-1.5 text-muted transition-colors hover:bg-accent-bg hover:text-accent"
              >
                <IconeVoir />
              </Link>
            </div>
            {arbre && <ArbreExecution composantes={arbre.composantes} tauxAttendu={arbre.tauxAttendu} />}
          </section>

          <section>
            <h2 className="mb-3 text-lg font-semibold">Décaissement cumulé</h2>
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <BarresDecaissement points={decaissement?.points ?? []} />
            </div>
          </section>

          <section>
            <h2 className="mb-3 text-lg font-semibold">Exécution physique et financière par composante</h2>
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              {arbre ? <PhysiqueFinancierParComposante composantes={arbre.composantes} /> : <p className="text-sm text-muted">Chargement…</p>}
            </div>
          </section>

          <section>
            <h2 className="mb-3 text-lg font-semibold">Engagements par nature</h2>
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              {synthese ? <EngagementsParNature lignes={synthese.engagementsParNature} /> : <p className="text-sm text-muted">Chargement…</p>}
            </div>
          </section>
        </div>

        <div className="flex min-w-0 flex-col gap-8">
          <section className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-lg font-semibold">Points d'attention</h2>
              <span className="text-sm text-muted">{alertesFiltrees.length} en cours</span>
            </div>
            <ul className="flex flex-col gap-2">
              {alertesFiltrees.map((a) => (
                <li key={a.uuid} className="flex items-center justify-between gap-3 rounded-[var(--radius-carte)] bg-nested px-3 py-2">
                  <Link to={`/composantes/activites/${a.uuid}`} className="min-w-0">
                    <p className="truncate text-sm">
                      <span className="chiffres mr-2 text-muted">{a.code}</span>
                      {a.libelle}
                    </p>
                    <p className="text-xs text-muted">{a.structure}</p>
                  </Link>
                  <PastilleVigilance vigilance={a.vigilance} className="shrink-0" />
                </li>
              ))}
              {alertes && alertesFiltrees.length === 0 && <p className="text-sm text-muted">Aucune alerte pour le moment.</p>}
            </ul>
          </section>

          {voitIndicateurs && (
            <section className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <div className="mb-3 flex items-center justify-between">
                <h2 className="text-lg font-semibold">Cadre de résultats</h2>
                <Link
                  to="/indicateurs"
                  title="Tout voir — Indicateurs de performance"
                  aria-label="Tout voir — Indicateurs de performance"
                  className="rounded-[var(--radius-controle)] p-1.5 text-muted transition-colors hover:bg-accent-bg hover:text-accent"
                >
                  <IconeVoir />
                </Link>
              </div>
              <AtteinteGlobaleIndicateurs indicateurs={indicateurs?.donnees ?? []} />
            </section>
          )}

          <section className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
            <h2 className="mb-3 text-lg font-semibold">Activités par statut</h2>
            {arbre ? <RepartitionStatutsActivites composantes={arbre.composantes} /> : <p className="text-sm text-muted">Chargement…</p>}
          </section>

          <section className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
            <h2 className="mb-1 text-lg font-semibold">Exécution par structure</h2>
            <p className="mb-3 text-xs text-muted">Structures responsables d'activités, les plus dotées d'abord.</p>
            {arbre ? (
              <ExecutionParStructure composantes={arbre.composantes} tauxAttendu={arbre.tauxAttendu} />
            ) : (
              <p className="text-sm text-muted">Chargement…</p>
            )}
          </section>

          <section className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-lg font-semibold">Activité récente</h2>
              <span className="rounded-full bg-ok-bg px-2 py-0.5 text-xs font-medium text-ok">Direct</span>
            </div>
            <ul className="flex flex-col gap-2">
              {activiteRecenteFiltree.map((e) => (
                <li key={e.id} className="flex items-start gap-2 text-sm">
                  <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${CLASSES_EVENEMENT[e.action] ?? 'bg-muted'}`} />
                  <span className="chiffres shrink-0 text-xs text-muted">{formaterDate(e.horodatage)}</span>
                  <span className="min-w-0">
                    {e.utilisateur && <strong>{e.utilisateur} — </strong>}
                    {e.libelleAction}
                    {e.code && ` — ${e.code}`}
                  </span>
                </li>
              ))}
              {activiteRecente && activiteRecenteFiltree.length === 0 && (
                <p className="text-sm text-muted">Aucune écriture récente.</p>
              )}
            </ul>
          </section>
        </div>
      </div>
    </div>
  )
}

const TONS_PILOTAGE = {
  neutre: 'text-text',
  alerte: 'text-bad',
  attente: 'text-eyebrow',
} as const

/** Indicateur de pilotage : une valeur (taux ou décompte) et son contexte en clair. */
function CartePilotage({
  libelle,
  valeur,
  detail,
  ton = 'neutre',
}: {
  libelle: string
  valeur: string
  detail?: string
  ton?: keyof typeof TONS_PILOTAGE
}) {
  return (
    <div className="min-w-0 rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
      <p className="text-[10.5px] font-semibold uppercase leading-tight tracking-[.13em] text-muted">{libelle}</p>
      <p className={`chiffres mt-1 text-xl font-extrabold tracking-tight ${TONS_PILOTAGE[ton]}`}>{valeur}</p>
      {detail && <p className="text-xs leading-snug text-muted">{detail}</p>}
    </div>
  )
}

const COULEURS_KPI = {
  accent: 'bg-accent',
  'accent-clair': 'bg-accent-clair',
  eyebrow: 'bg-eyebrow',
  bad: 'bg-bad',
} as const

function CarteKpi({
  libelle,
  montant,
  taux,
  libelleTaux,
  sousTexte,
  couleur,
  chargement,
}: {
  libelle: string
  montant: number | null
  taux?: number | null
  libelleTaux?: string
  sousTexte?: string
  couleur: keyof typeof COULEURS_KPI
  chargement: boolean
}) {
  return (
    <div className="relative min-w-0 overflow-hidden rounded-[var(--radius-carte)] bg-surface p-4 pl-5 shadow-[var(--shadow-carte)]">
      <span className={`absolute inset-y-0 left-0 w-[3px] ${COULEURS_KPI[couleur]}`} aria-hidden="true" />
      <p className="truncate text-[10.5px] font-semibold uppercase tracking-[.13em] text-muted">{libelle}</p>
      <p className="chiffres mt-1.5 mb-0.5 flex flex-wrap items-baseline gap-x-1 text-[26px] font-extrabold tracking-tight">
        {chargement || montant === null ? '—' : formaterMds(montant)}
        <small className="chiffres text-xs font-semibold text-muted">Mds FCFA</small>
      </p>
      {taux !== undefined && taux !== null && (
        <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted">
          <span className="chiffres rounded-full bg-accent-bg px-2 py-0.5 font-semibold text-accent">{formaterTaux(taux)}</span>
          {libelleTaux}
        </p>
      )}
      {taux === undefined && sousTexte && <p className="truncate text-xs text-muted">{sousTexte}</p>}
    </div>
  )
}
