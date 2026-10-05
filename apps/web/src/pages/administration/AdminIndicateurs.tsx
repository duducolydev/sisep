import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { indicateursQueryOptions, type Indicateur, type TypeIndicateur } from '@/lib/indicateurs'
import { usePeut } from '@/lib/use-permissions'
import { AdminOnglets } from '@/components/AdminOnglets'
import { DefinirIndicateurModale } from '@/components/DefinirIndicateurModale'
import { ModifierIndicateurModale } from '@/components/ModifierIndicateurModale'
import { budgetExecutionQueryOptions } from '@/lib/budget'
import { IconeBouton, IconeModifier, IconeRecherche, IconePlus } from '@/components/Icones'
import { Pagination } from '@/components/Pagination'
import { Squelette } from '@/components/Squelette'
import { trancherPage } from '@/lib/pagination'

const TAILLE_PAGE = 10

export function AdminIndicateurs() {
  const { data: indicateurs, isLoading, isError } = useQuery(indicateursQueryOptions())
  const { data: composantes } = useQuery(budgetExecutionQueryOptions('composante'))
  const peutDefinir = usePeut('indicateurs.definir')

  const [searchParams, setSearchParams] = useSearchParams()
  const [modaleCreation, setModaleCreation] = useState(false)
  const [indicateurEnEdition, setIndicateurEnEdition] = useState<Indicateur | null>(null)

  const recherche = searchParams.get('q') ?? ''
  const type = (searchParams.get('type') as TypeIndicateur | null) ?? ''
  const page = Math.max(1, Number(searchParams.get('page') ?? '1'))

  function definirFiltre(cle: 'q' | 'type', valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') {
      suivant.delete(cle)
    } else {
      suivant.set(cle, valeur)
    }
    suivant.delete('page')
    setSearchParams(suivant, { replace: true })
  }

  function definirPage(suivante: number) {
    const suivant = new URLSearchParams(searchParams)
    suivant.set('page', String(suivante))
    setSearchParams(suivant, { replace: true })
  }

  const indicateursFiltres = useMemo(() => {
    const rechercheLC = recherche.trim().toLowerCase()

    return (indicateurs?.donnees ?? []).filter((i) => {
      if (type !== '' && i.type !== type) return false
      if (rechercheLC === '') return true

      return i.code.toLowerCase().includes(rechercheLC) || i.libelle.toLowerCase().includes(rechercheLC)
    })
  }, [indicateurs, recherche, type])

  const totalPages = Math.max(1, Math.ceil(indicateursFiltres.length / TAILLE_PAGE))
  const indicateursPage = trancherPage(indicateursFiltres, page, TAILLE_PAGE)

  return (
    <div>
      <p className="font-titre text-eyebrow uppercase tracking-wide">Backoffice</p>
      <h1 className="text-2xl">Administration</h1>
      <p className="mb-6 text-muted">Référentiels, parties prenantes, rôles et permissions, journal des actions.</p>

      <AdminOnglets />

      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold">Définition des indicateurs de performance</h2>
        {peutDefinir && (
          <button
            type="button"
            onClick={() => setModaleCreation(true)}
            className="flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d"
          >
            <IconePlus />
            Nouvel indicateur
          </button>
        )}
      </div>

      <div className="mb-4 flex flex-wrap gap-3" role="search" aria-label="Filtres des indicateurs de performance">
        <div className="relative min-w-48 flex-1">
          <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted">
            <IconeRecherche />
          </span>
          <input
            type="search"
            aria-label="Rechercher un indicateur"
            placeholder="Rechercher un code ou un libellé…"
            value={recherche}
            onChange={(e) => definirFiltre('q', e.target.value)}
            className="w-full rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          />
        </div>
        <select
          aria-label="Type"
          value={type}
          onChange={(e) => definirFiltre('type', e.target.value)}
          className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2"
        >
          <option value="">Tous les types</option>
          <option value="IOD">IOD — objectif de développement</option>
          <option value="IRI">IRI — résultat intermédiaire</option>
        </select>
      </div>

      {isLoading && <Squelette lignes={4} />}
      {isError && <p className="text-bad">Impossible de charger les indicateurs de performance. Réessayez.</p>}

      {indicateurs && (
        <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left text-muted">
                <th className="px-4 py-3 font-normal">Indicateur</th>
                <th className="px-4 py-3 font-normal">Type</th>
                <th className="px-4 py-3 font-normal">Unité</th>
                <th className="px-4 py-3 font-normal">Fréquence</th>
                <th className="px-4 py-3 font-normal">Source</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {indicateursPage.map((i) => (
                <tr key={i.uuid} className="border-b border-border last:border-0 hover:bg-hover">
                  <td className="px-4 py-3">
                    <span className="chiffres mr-2 text-muted">{i.code}</span>
                    {i.libelle}
                    {!i.actif && <span className="ml-2 rounded-full bg-nested px-2 py-0.5 text-xs text-muted">Inactif</span>}
                  </td>
                  <td className="px-4 py-3">{i.type === 'IOD' ? "Objectif de développement" : 'Résultat intermédiaire'}</td>
                  <td className="px-4 py-3">{i.unite}</td>
                  <td className="px-4 py-3 capitalize">{i.frequence}</td>
                  <td className="px-4 py-3 text-muted">{i.source}</td>
                  <td className="px-4 py-3 text-right">
                    {peutDefinir && (
                      <IconeBouton libelle={`Modifier ${i.code}`} onClick={() => setIndicateurEnEdition(i)}>
                        <IconeModifier />
                      </IconeBouton>
                    )}
                  </td>
                </tr>
              ))}
              {indicateursPage.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-6 text-center text-muted">
                    Aucun indicateur ne correspond à ces filtres.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
          <Pagination
            page={page}
            totalPages={totalPages}
            total={indicateursFiltres.length}
            taille={TAILLE_PAGE}
            onChangerPage={definirPage}
          />
        </div>
      )}

      {modaleCreation && (
        <DefinirIndicateurModale composantes={composantes?.donnees ?? []} onFermer={() => setModaleCreation(false)} />
      )}
      {indicateurEnEdition && (
        <ModifierIndicateurModale indicateur={indicateurEnEdition} onFermer={() => setIndicateurEnEdition(null)} />
      )}
    </div>
  )
}
