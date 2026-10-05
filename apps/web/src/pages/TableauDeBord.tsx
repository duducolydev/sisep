import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router'
import { projetCourantQueryOptions, arbreQueryOptions } from '@/lib/composantes'
import { decaissementQueryOptions } from '@/lib/budget'
import { indicateursQueryOptions } from '@/lib/indicateurs'
import { syntheseQueryOptions, alertesQueryOptions, activiteRecenteQueryOptions } from '@/lib/tableau-de-bord'
import { usePeriodeFiltre } from '@/lib/periode'
import { formaterMds, formaterTaux, formaterDate } from '@/lib/format'
import { ArbreExecution } from '@/components/ArbreExecution'
import { CourbeDecaissement } from '@/components/CourbeDecaissement'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { PastilleVigilance } from '@/components/PastilleVigilance'
import { SelecteurExercice } from '@/components/SelecteurExercice'
import { SelecteurPeriode } from '@/components/SelecteurPeriode'
import { IconeVoir } from '@/components/Icones'
import { usePeut } from '@/lib/use-permissions'

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
  const { data: indicateurs } = useQuery({ ...indicateursQueryOptions(), enabled: voitIndicateurs })
  const { data: activiteRecente } = useQuery(activiteRecenteQueryOptions(projet?.uuid))

  const alertesFiltrees = (alertes?.alertes ?? []).filter((a) => a.dateFinPrevue === null || correspondAPeriode(a.dateFinPrevue))
  const activiteRecenteFiltree = (activiteRecente?.evenements ?? []).filter((e) => correspondAPeriode(e.horodatage))

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-titre text-eyebrow uppercase tracking-wide">Tableau de bord</p>
          <h1 className="text-2xl">Synthèse du projet</h1>
          <p className="text-muted">
            Exercice {synthese?.exercice ?? '—'} · indicateurs de performance clés, arbre d'exécution, décaissement cumulé, points d'attention — § 2.1.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <SelecteurExercice projetUuid={projet?.uuid} />
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
              <CourbeDecaissement points={decaissement?.points ?? []} />
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
              <ul className="flex flex-col gap-3">
                {(indicateurs?.donnees ?? []).slice(0, 5).map((i) => (
                  <li key={i.uuid}>
                    <Link to={`/indicateurs/${i.uuid}`} className="mb-1 block truncate text-sm">
                      <span className="chiffres mr-2 text-muted">{i.code}</span>
                      {i.libelle}
                    </Link>
                    <BarreAtteinte taux={i.tauxAtteinte} />
                  </li>
                ))}
              </ul>
            </section>
          )}

          <section className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-lg font-semibold">Activité récente</h2>
              <span className="rounded-full bg-ok-bg px-2 py-0.5 text-xs font-medium text-ok">Direct</span>
            </div>
            <ul className="flex flex-col gap-2">
              {activiteRecenteFiltree.map((e) => (
                <li key={`${e.horodatage}-${e.type}-${e.action}-${e.code ?? ''}`} className="flex items-start gap-2 text-sm">
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
