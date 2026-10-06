import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams, Link, useNavigate } from 'react-router'
import { budgetExecutionQueryOptions, decaissementQueryOptions, executionPluriannuelleQueryOptions } from '@/lib/budget'
import { projetCourantQueryOptions } from '@/lib/composantes'
import { formaterFcfa, formaterTaux } from '@/lib/format'
import { urlExportBudget } from '@/lib/exports'
import { usePeut } from '@/lib/use-permissions'
import { JournalBudgetaire } from '@/components/JournalBudgetaire'
import { BarreExecution } from '@/components/BarreExecution'
import { CourbeDecaissement } from '@/components/CourbeDecaissement'
import { BarresMontantsExecution } from '@/components/BarresMontantsExecution'
import { BarresTauxExecution } from '@/components/BarresTauxExecution'
import { IconeRecherche } from '@/components/Icones'
import { BoutonExporter } from '@/components/BoutonExporter'
import { Pagination } from '@/components/Pagination'
import { Squelette } from '@/components/Squelette'
import { trancherPage } from '@/lib/pagination'

const TAILLE_PAGE = 15

export function Budget() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const composanteUuid = searchParams.get('composante') ?? undefined
  const recherche = searchParams.get('q') ?? ''
  const page = Math.max(1, Number(searchParams.get('page') ?? '1'))

  const { data: projet } = useQuery(projetCourantQueryOptions())
  const { data: composantes } = useQuery(budgetExecutionQueryOptions('composante'))
  const { data: activites, isLoading, isError } = useQuery(budgetExecutionQueryOptions('activite', composanteUuid))
  const { data: decaissement } = useQuery(decaissementQueryOptions(projet?.uuid))
  const { data: executionPluriannuelle } = useQuery(executionPluriannuelleQueryOptions(projet?.uuid))
  const peutExporter = usePeut('exports.generer')
  const peutConsulterJournal = usePeut('journal.consulter')

  const activitesFiltrees = useMemo(() => {
    const rechercheLC = recherche.trim().toLowerCase()
    if (rechercheLC === '') return activites?.donnees ?? []

    return (activites?.donnees ?? []).filter(
      (a) => a.code.toLowerCase().includes(rechercheLC) || a.libelle.toLowerCase().includes(rechercheLC),
    )
  }, [activites, recherche])

  const totalPages = Math.max(1, Math.ceil(activitesFiltrees.length / TAILLE_PAGE))
  const activitesPage = trancherPage(activitesFiltrees, page, TAILLE_PAGE)

  const classementMoinsExecutees = useMemo(() => {
    if (!activites) return []
    return [...activites.donnees]
      .filter((a) => a.tauxExecution !== null)
      .sort((a, b) => (a.tauxExecution ?? 0) - (b.tauxExecution ?? 0))
      .slice(0, 5)
  }, [activites])

  function definirComposante(valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') {
      suivant.delete('composante')
    } else {
      suivant.set('composante', valeur)
    }
    suivant.delete('page')
    setSearchParams(suivant, { replace: true })
  }

  function definirRecherche(valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') {
      suivant.delete('q')
    } else {
      suivant.set('q', valeur)
    }
    suivant.delete('page')
    setSearchParams(suivant, { replace: true })
  }

  function definirPage(suivante: number) {
    const suivant = new URLSearchParams(searchParams)
    suivant.set('page', String(suivante))
    setSearchParams(suivant, { replace: true })
  }

  return (
    <div className="flex flex-col gap-8">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl">Budget</h1>
          <p className="text-muted">
            Exercice {activites?.exercice ?? '—'} · notifié, engagé, dépensé, disponible, taux d'exécution — § 2.3.
          </p>
        </div>
        {peutExporter && <BoutonExporter href={urlExportBudget(activites?.exercice, composanteUuid)} libelle="Exporter" />}
      </div>

      {executionPluriannuelle && (
        <section>
          <h2 className="mb-3 text-lg font-semibold">Exécution financière pluriannuelle</h2>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <p className="mb-3 text-sm font-semibold">Taux d'exécution</p>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_2fr]">
                <div>
                  <p className="mb-1 text-xs text-muted">Global</p>
                  <BarresTauxExecution
                    donnees={[{ libelle: 'Cumulé', tauxExecution: executionPluriannuelle.global.tauxExecution }]}
                  />
                </div>
                <div>
                  <p className="mb-1 text-xs text-muted">Par exercice</p>
                  <BarresTauxExecution
                    donnees={executionPluriannuelle.annees.map((a) => ({
                      libelle: String(a.annee),
                      tauxExecution: a.tauxExecution,
                    }))}
                  />
                </div>
              </div>
            </div>

            <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
              <p className="mb-3 text-sm font-semibold">Montants d'exécution</p>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_2fr]">
                <div>
                  <p className="mb-1 text-xs text-muted">Global</p>
                  <BarresMontantsExecution
                    donnees={[
                      {
                        libelle: 'Cumulé',
                        execution: executionPluriannuelle.global.depense,
                        objectif: executionPluriannuelle.global.notifie,
                      },
                    ]}
                  />
                </div>
                <div>
                  <p className="mb-1 text-xs text-muted">Par exercice</p>
                  <BarresMontantsExecution
                    donnees={executionPluriannuelle.annees.map((a) => ({
                      libelle: String(a.annee),
                      execution: a.depense,
                      objectif: a.notifie,
                    }))}
                  />
                </div>
              </div>
            </div>
          </div>
        </section>
      )}

      <section>
        <h2 className="mb-3 text-lg font-semibold">Synthèse par composante</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {composantes?.donnees.map((c) => (
            <button
              key={c.uuid}
              type="button"
              onClick={() => definirComposante(composanteUuid === c.uuid ? '' : c.uuid)}
              className={`min-w-0 rounded-[var(--radius-carte)] border bg-surface p-4 text-left shadow-[var(--shadow-carte)] transition-colors ${
                composanteUuid === c.uuid ? 'border-accent ring-2 ring-accent' : 'border-border hover:border-accent'
              }`}
            >
              <p className="chiffres text-sm text-muted">{c.code}</p>
              <p className="mb-2 font-semibold">{c.libelle}</p>
              <BarreExecution notifie={c.notifie} engage={c.engage} depense={c.depense} />
            </button>
          ))}
        </div>
      </section>

      <section>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-semibold">Décaissement cumulé</h2>
        </div>
        <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
          <CourbeDecaissement points={decaissement?.points ?? []} />
        </div>
      </section>

      {classementMoinsExecutees.length > 0 && (
        <section>
          <h2 className="mb-3 text-lg font-semibold">Activités les moins exécutées</h2>
          <ul className="flex flex-col gap-2">
            {classementMoinsExecutees.map((a) => (
              <li
                key={a.uuid}
                className="flex items-center justify-between rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]"
              >
                <Link to={`/composantes/activites/${a.uuid}`} className="text-accent">
                  <span className="chiffres mr-2 text-muted">{a.code}</span>
                  {a.libelle}
                </Link>
                <span className="chiffres text-bad">{formaterTaux(a.tauxExecution)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">Toutes les activités</h2>
          <div className="flex flex-wrap gap-3" role="search" aria-label="Filtres des activités budgétaires">
            <div className="relative min-w-48 flex-1">
              <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted">
                <IconeRecherche />
              </span>
              <input
                type="search"
                aria-label="Rechercher une activité"
                placeholder="Rechercher un code ou un libellé…"
                value={recherche}
                onChange={(e) => definirRecherche(e.target.value)}
                className="w-full rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
              />
            </div>
            <select
              aria-label="Filtrer par composante"
              value={composanteUuid ?? ''}
              onChange={(e) => definirComposante(e.target.value)}
              className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2"
            >
              <option value="">Toutes les composantes</option>
              {composantes?.donnees.map((c) => (
                <option key={c.uuid} value={c.uuid}>
                  {c.code} — {c.libelle}
                </option>
              ))}
            </select>
          </div>
        </div>

        {isLoading && <Squelette lignes={4} />}
        {isError && <p className="text-bad">Impossible de charger le budget. Réessayez.</p>}

        {activites && (
          <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-muted">
                  <th className="px-4 py-3 font-normal">Activité</th>
                  <th className="px-4 py-3 text-right font-normal">Notifié</th>
                  <th className="px-4 py-3 text-right font-normal">Engagé</th>
                  <th className="px-4 py-3 text-right font-normal">Dépensé</th>
                  <th className="px-4 py-3 text-right font-normal">Disponible</th>
                  <th className="px-4 py-3 text-right font-normal">Taux d'exécution</th>
                </tr>
              </thead>
              <tbody>
                {activitesPage.map((a) => (
                  <tr
                    key={a.uuid}
                    onClick={(e) => {
                      if ((e.target as HTMLElement).closest('a')) return
                      void navigate(`/composantes/activites/${a.uuid}`)
                    }}
                    className="cursor-pointer border-b border-border last:border-0 hover:bg-hover"
                  >
                    <td className="px-4 py-3">
                      <Link to={`/composantes/activites/${a.uuid}`} className="text-accent">
                        <span className="chiffres mr-2 text-muted">{a.code}</span>
                        {a.libelle}
                      </Link>
                    </td>
                    <td className="chiffres px-4 py-3 text-right">{a.notifie === null ? '—' : formaterFcfa(a.notifie)}</td>
                    <td className="chiffres px-4 py-3 text-right">{a.engage === null ? '—' : formaterFcfa(a.engage)}</td>
                    <td className="chiffres px-4 py-3 text-right">{a.depense === null ? '—' : formaterFcfa(a.depense)}</td>
                    <td className="chiffres px-4 py-3 text-right">{a.disponible === null ? '—' : formaterFcfa(a.disponible)}</td>
                    <td className="chiffres px-4 py-3 text-right">{formaterTaux(a.tauxExecution)}</td>
                  </tr>
                ))}
                {activitesPage.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-4 py-6 text-center text-muted">
                      Aucune activité ne correspond à ces filtres.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
            <Pagination
              page={page}
              totalPages={totalPages}
              total={activitesFiltrees.length}
              taille={TAILLE_PAGE}
              onChangerPage={definirPage}
            />
          </div>
        )}
      </section>

      {/* Réservé à qui détient journal.consulter : lire qui a validé une
          dépense est un droit d'audit, pas un droit d'écran. */}
      {peutConsulterJournal && (
        <section className="mt-6">
          <JournalBudgetaire />
        </section>
      )}
    </div>
  )
}
