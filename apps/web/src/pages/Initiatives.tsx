import { useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { projetCourantQueryOptions, arbreQueryOptions } from '@/lib/composantes'
import {
  initiativesQueryOptions,
  responsablesEligiblesQueryOptions,
  LIBELLES_STATUT_INITIATIVE,
  type ActiviteAvecProjet,
  type StatutInitiativeActivite,
} from '@/lib/initiatives'
import { urlExportInitiativesXlsx, urlExportInitiativesPdf } from '@/lib/exports'
import { usePeut } from '@/lib/use-permissions'
import { CreerInitiativeModale } from '@/components/CreerInitiativeModale'
import { RepartitionStatutsInitiatives } from '@/components/RepartitionStatutsInitiatives'
import { VueCartesProjets } from '@/components/initiatives/VueCartesProjets'
import { VueTableauActivites } from '@/components/initiatives/VueTableauActivites'
import { VueKanbanActivites } from '@/components/initiatives/VueKanbanActivites'
import { ProgressionParActivite } from '@/components/initiatives/ProgressionParActivite'
import { ProgressionParProjet } from '@/components/initiatives/ProgressionParProjet'
import { ProgressionParTache, type TacheAvecContexte } from '@/components/initiatives/ProgressionParTache'
import { ChargeParResponsable } from '@/components/initiatives/ChargeParResponsable'
import { ComboboxMultiple } from '@/components/ComboboxMultiple'
import { IconePlus, IconeRecherche, IconeComposante, IconeVueCartes, IconeVueTableau, IconeVueKanban } from '@/components/Icones'
import { BoutonExporter } from '@/components/BoutonExporter'
import { Squelette } from '@/components/Squelette'
import { formaterTaux } from '@/lib/format'

type Vue = 'cartes' | 'tableau' | 'kanban'

const VUES: { valeur: Vue; libelle: string; icone: ReactNode }[] = [
  { valeur: 'cartes', libelle: 'Cartes KPI', icone: <IconeVueCartes /> },
  { valeur: 'tableau', libelle: 'Tableau', icone: <IconeVueTableau /> },
  { valeur: 'kanban', libelle: 'Kanban', icone: <IconeVueKanban /> },
]

export function Initiatives() {
  const { data: projet } = useQuery(projetCourantQueryOptions())
  const { data: arbre } = useQuery(arbreQueryOptions(projet?.uuid, 3))
  const { data: initiatives, isLoading, isError } = useQuery(initiativesQueryOptions())
  const { data: responsables } = useQuery(responsablesEligiblesQueryOptions())
  const peutGerer = usePeut('initiatives.gerer')
  const peutExporter = usePeut('exports.generer')
  const [modaleCreation, setModaleCreation] = useState(false)
  const [searchParams, setSearchParams] = useSearchParams()

  const vue = (searchParams.get('vue') as Vue | null) ?? 'cartes'
  const recherche = searchParams.get('q') ?? ''
  const statutsSelectionnes = useMemo(() => (searchParams.get('statut') ?? '').split(',').filter(Boolean), [searchParams])
  const responsablesSelectionnes = useMemo(() => (searchParams.get('responsable') ?? '').split(',').filter(Boolean), [searchParams])
  const lienPtba = searchParams.get('lienPtba') ?? 'tous'

  function definirVue(suivante: Vue) {
    const suivant = new URLSearchParams(searchParams)
    suivant.set('vue', suivante)
    setSearchParams(suivant, { replace: true })
  }

  function definirRecherche(valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') suivant.delete('q')
    else suivant.set('q', valeur)
    setSearchParams(suivant, { replace: true })
  }

  function definirStatuts(valeurs: string[]) {
    const suivant = new URLSearchParams(searchParams)
    if (valeurs.length === 0) suivant.delete('statut')
    else suivant.set('statut', valeurs.join(','))
    setSearchParams(suivant, { replace: true })
  }

  function definirResponsables(valeurs: string[]) {
    const suivant = new URLSearchParams(searchParams)
    if (valeurs.length === 0) suivant.delete('responsable')
    else suivant.set('responsable', valeurs.join(','))
    setSearchParams(suivant, { replace: true })
  }

  function definirLienPtba(valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === 'tous') suivant.delete('lienPtba')
    else suivant.set('lienPtba', valeur)
    setSearchParams(suivant, { replace: true })
  }

  const activites = useMemo(
    (): ActiviteAvecProjet[] =>
      (initiatives?.donnees ?? []).flatMap((i) =>
        i.activites.map((a) => ({
          ...a,
          initiativeUuid: i.uuid,
          initiativeLibelle: i.libelle,
          initiativeLienPtba: i.lienPtba,
        })),
      ),
    [initiatives],
  )

  const activitesFiltrees = useMemo(() => {
    const rechercheLC = recherche.trim().toLowerCase()

    return activites.filter((a) => {
      if (rechercheLC !== '' && !a.libelle.toLowerCase().includes(rechercheLC) && !a.initiativeLibelle.toLowerCase().includes(rechercheLC)) {
        return false
      }
      if (statutsSelectionnes.length > 0 && !statutsSelectionnes.includes(a.statut)) return false
      if (responsablesSelectionnes.length > 0 && !a.responsables.some((r) => responsablesSelectionnes.includes(r.uuid))) return false
      if (lienPtba === 'independant' && null !== a.initiativeLienPtba) return false
      if (lienPtba === 'lie' && null === a.initiativeLienPtba) return false

      return true
    })
  }, [activites, recherche, statutsSelectionnes, responsablesSelectionnes, lienPtba])

  const kpis = useMemo(() => {
    const avancements = activitesFiltrees.map((a) => a.avancement).filter((a): a is number => a !== null)
    const tauxMoyen = avancements.length > 0 ? avancements.reduce((s, a) => s + a, 0) / avancements.length : null
    const nbProjets = new Set(activitesFiltrees.map((a) => a.initiativeUuid)).size

    return {
      nbProjets,
      nbActivites: activitesFiltrees.length,
      nbEnRetard: activitesFiltrees.filter((a) => a.statut === 'en_retard').length,
      nbARisque: activitesFiltrees.filter((a) => a.statut === 'a_risque').length,
      tauxMoyen,
    }
  }, [activitesFiltrees])

  const tachesFiltrees = useMemo(
    (): TacheAvecContexte[] =>
      activitesFiltrees.flatMap((a) =>
        a.taches.map((t) => ({
          uuid: t.uuid,
          libelle: t.libelle,
          statut: t.statut,
          activiteLibelle: a.libelle,
          initiativeLibelle: a.initiativeLibelle,
          terminee: null !== t.dateRealisation,
        })),
      ),
    [activitesFiltrees],
  )

  const statutsTaches = useMemo(() => tachesFiltrees.map((t) => t.statut), [tachesFiltrees])

  const projetsFiltres = useMemo(() => {
    const initiativeUuidsFiltrees = new Set(activitesFiltrees.map((a) => a.initiativeUuid))
    return (initiatives?.donnees ?? []).filter((i) => initiativeUuidsFiltrees.has(i.uuid))
  }, [initiatives, activitesFiltrees])

  const optionsStatut = (Object.entries(LIBELLES_STATUT_INITIATIVE) as [StatutInitiativeActivite, string][]).map(([valeur, libelle]) => ({
    value: valeur,
    label: libelle,
  }))
  const optionsResponsable = (responsables?.donnees ?? []).map((r) => ({ value: r.uuid, label: r.nomComplet }))

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="font-titre text-eyebrow uppercase tracking-wide">Pilotage</p>
          <h1 className="text-2xl">Projets</h1>
          <p className="text-muted">Suivi des projets transverses — activités, tâches, échéances et avancement.</p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {peutExporter && (
            <>
              <BoutonExporter href={urlExportInitiativesXlsx()} libelle="Excel" />
              <BoutonExporter href={urlExportInitiativesPdf()} libelle="PDF" />
            </>
          )}
          {peutGerer && (
            <button
              type="button"
              onClick={() => setModaleCreation(true)}
              className="flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d"
            >
              <IconePlus />
              Nouveau projet
            </button>
          )}
        </div>
      </div>

      {isLoading && <Squelette lignes={4} />}
      {isError && <p className="text-bad">Impossible de charger les projets. Réessayez.</p>}

      {initiatives && (
        <>
          <section className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
            <CarteKpiInitiative libelle="Projets" valeur={kpis.nbProjets} couleur="accent" />
            <CarteKpiInitiative libelle="Activités" valeur={kpis.nbActivites} couleur="accent-clair" />
            <CarteKpiInitiative libelle="Avancement moyen" valeur={formaterTaux(kpis.tauxMoyen)} couleur="eyebrow" />
            <CarteKpiInitiative libelle="En retard / à risque" valeur={`${kpis.nbEnRetard} / ${kpis.nbARisque}`} couleur="bad" />
          </section>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <p className="mb-3 text-sm font-semibold">Répartition des activités par statut</p>
              <RepartitionStatutsInitiatives statuts={activitesFiltrees.map((a) => a.statut)} />
            </div>
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <p className="mb-3 text-sm font-semibold">Tâches par progression</p>
              <ProgressionParTache taches={tachesFiltrees} />
            </div>
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <p className="mb-3 text-sm font-semibold">Progression par activité</p>
              <ProgressionParActivite activites={activitesFiltrees} />
            </div>
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <p className="mb-3 text-sm font-semibold">Progression par projet</p>
              <ProgressionParProjet projets={projetsFiltres} />
            </div>
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <p className="mb-3 text-sm font-semibold">Répartition des tâches par statut</p>
              <RepartitionStatutsInitiatives statuts={statutsTaches} />
            </div>
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <p className="mb-3 text-sm font-semibold">Charge par responsable</p>
              <ChargeParResponsable activites={activitesFiltrees} />
            </div>
          </div>

          <div
            className="flex flex-wrap items-center gap-3 rounded-[var(--radius-carte)] bg-surface p-3 shadow-[var(--shadow-carte)]"
            role="search"
            aria-label="Filtres des projets"
          >
            <div className="relative min-w-48 flex-1">
              <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted">
                <IconeRecherche />
              </span>
              <input
                type="search"
                aria-label="Recherche"
                placeholder="Rechercher une activité ou un projet…"
                value={recherche}
                onChange={(e) => definirRecherche(e.target.value)}
                className="w-full rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
              />
            </div>

            <div className="w-56">
              <ComboboxMultiple options={optionsStatut} selectionnes={statutsSelectionnes} onChange={definirStatuts} placeholder="Tous les statuts" />
            </div>

            <div className="w-56">
              <ComboboxMultiple
                options={optionsResponsable}
                selectionnes={responsablesSelectionnes}
                onChange={definirResponsables}
                placeholder="Tous les responsables"
              />
            </div>

            <div className="relative">
              <span className={`pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 ${'tous' !== lienPtba ? 'text-accent' : 'text-muted'}`}>
                <IconeComposante />
              </span>
              <select
                aria-label="Lien PTBA"
                value={lienPtba}
                onChange={(e) => definirLienPtba(e.target.value)}
                className="rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
              >
                <option value="tous">Indépendant ou lié au PTBA</option>
                <option value="independant">Indépendant du PTBA</option>
                <option value="lie">Lié au PTBA</option>
              </select>
            </div>
          </div>

          <div className="flex items-center gap-1 self-start rounded-[var(--radius-controle)] border border-border bg-surface p-1">
            {VUES.map((v) => (
              <button
                key={v.valeur}
                type="button"
                onClick={() => definirVue(v.valeur)}
                aria-pressed={vue === v.valeur}
                className={`flex items-center gap-1.5 rounded-[var(--radius-controle)] px-3 py-1.5 text-sm font-semibold transition-colors ${
                  vue === v.valeur ? 'bg-accent text-white' : 'text-muted hover:bg-nested'
                }`}
              >
                {v.icone}
                {v.libelle}
              </button>
            ))}
          </div>

          {initiatives.donnees.length === 0 ? (
            <p className="text-muted">Aucun projet pour le moment.</p>
          ) : (
            <>
              {vue === 'cartes' && <VueCartesProjets projets={initiatives.donnees} activitesFiltrees={activitesFiltrees} />}
              {vue === 'tableau' && <VueTableauActivites activitesFiltrees={activitesFiltrees} />}
              {vue === 'kanban' && <VueKanbanActivites activitesFiltrees={activitesFiltrees} />}
            </>
          )}
        </>
      )}

      {modaleCreation && <CreerInitiativeModale composantes={arbre?.composantes ?? []} onFermer={() => setModaleCreation(false)} />}
    </div>
  )
}

const COULEURS_KPI = {
  accent: 'bg-accent',
  'accent-clair': 'bg-accent-clair',
  eyebrow: 'bg-eyebrow',
  bad: 'bg-bad',
} as const

function CarteKpiInitiative({
  libelle,
  valeur,
  couleur,
}: {
  libelle: string
  valeur: string | number
  couleur: keyof typeof COULEURS_KPI
}) {
  return (
    <div className="relative min-w-0 overflow-hidden rounded-[var(--radius-carte)] bg-surface p-4 pl-5 shadow-[var(--shadow-carte)]">
      <span className={`absolute inset-y-0 left-0 w-[3px] ${COULEURS_KPI[couleur]}`} aria-hidden="true" />
      <p className="truncate text-[10.5px] font-semibold uppercase tracking-[.13em] text-muted">{libelle}</p>
      <p className="chiffres mt-1.5 text-[26px] font-extrabold tracking-tight">{valeur}</p>
    </div>
  )
}
