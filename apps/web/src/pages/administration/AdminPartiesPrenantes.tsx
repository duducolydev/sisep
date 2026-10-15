import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { structuresDetailQueryOptions, pointsFocauxQueryOptions, supprimerStructure, type StructureDetail } from '@/lib/parties-prenantes'
import { usePeut } from '@/lib/use-permissions'
import { queryClient } from '@/lib/query-client'
import { ApiError } from '@/lib/api-client'
import { AdminOnglets } from '@/components/AdminOnglets'
import { StructureModale } from '@/components/StructureModale'
import { LIBELLES_TYPE_STRUCTURE } from '@/lib/types-structure'
import { IconeBouton, IconeModifier, IconeSupprimer, IconeRecherche, IconePlus } from '@/components/Icones'
import { Pagination } from '@/components/Pagination'
import { Squelette } from '@/components/Squelette'
import { trancherPage } from '@/lib/pagination'
import { useConfirmer } from '@/lib/confirmation'

const TAILLE_PAGE = 10

export function AdminPartiesPrenantes() {
  const confirmer = useConfirmer()
  const { data: structures, isLoading, isError } = useQuery(structuresDetailQueryOptions(true))
  const { data: pointsFocaux } = useQuery(pointsFocauxQueryOptions())
  const peutGerer = usePeut('structures.gerer')

  const [searchParams, setSearchParams] = useSearchParams()
  const [modaleCreation, setModaleCreation] = useState(false)
  const [structureEnEdition, setStructureEnEdition] = useState<StructureDetail | null>(null)
  const [erreur, setErreur] = useState<string | null>(null)
  const [suppressionEnCours, setSuppressionEnCours] = useState<string | null>(null)

  const recherche = searchParams.get('q') ?? ''
  const type = searchParams.get('type') ?? ''
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

  function pointFocalPrincipal(structureUuid: string) {
    return pointsFocaux?.donnees.find((p) => p.structure.uuid === structureUuid && p.principal)
      ?? pointsFocaux?.donnees.find((p) => p.structure.uuid === structureUuid)
  }

  const structuresFiltrees = useMemo(() => {
    const rechercheLC = recherche.trim().toLowerCase()

    return (structures?.donnees ?? []).filter((s) => {
      if (type !== '' && s.type !== type) return false
      if (rechercheLC === '') return true

      return s.sigle.toLowerCase().includes(rechercheLC) || s.denomination.toLowerCase().includes(rechercheLC)
    })
  }, [structures, recherche, type])

  const totalPages = Math.max(1, Math.ceil(structuresFiltrees.length / TAILLE_PAGE))
  const structuresPage = trancherPage(structuresFiltrees, page, TAILLE_PAGE)

  async function supprimer(structure: StructureDetail) {
    setErreur(null)
    setSuppressionEnCours(structure.uuid)
    try {
      await supprimerStructure(structure.uuid)
      await queryClient.invalidateQueries({ queryKey: ['structures'] })
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setSuppressionEnCours(null)
    }
  }

  return (
    <div>
      <p className="font-titre text-eyebrow uppercase tracking-wide">Backoffice</p>
      <h1 className="text-2xl">Administration</h1>
      <p className="mb-6 text-muted">Référentiels, parties prenantes, rôles et permissions, journal des actions.</p>

      <AdminOnglets />

      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold">Structures et points focaux</h2>
        {peutGerer && (
          <button
            type="button"
            onClick={() => setModaleCreation(true)}
            className="flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d"
          >
            <IconePlus />
            Ajouter une structure
          </button>
        )}
      </div>

      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <div className="mb-4 flex flex-wrap gap-3" role="search" aria-label="Filtres des structures">
        <div className="relative min-w-48 flex-1">
          <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted">
            <IconeRecherche />
          </span>
          <input
            type="search"
            aria-label="Rechercher une structure"
            placeholder="Rechercher un sigle ou une dénomination…"
            value={recherche}
            onChange={(e) => definirFiltre('q', e.target.value)}
            className="w-full rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          />
        </div>
        <select
          aria-label="Type de structure"
          value={type}
          onChange={(e) => definirFiltre('type', e.target.value)}
          className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2"
        >
          <option value="">Tous les types</option>
          {Object.entries(LIBELLES_TYPE_STRUCTURE).map(([valeur, libelle]) => (
            <option key={valeur} value={valeur}>
              {libelle}
            </option>
          ))}
        </select>
      </div>

      {isLoading && <Squelette lignes={4} />}
      {isError && <p className="text-bad">Impossible de charger les structures. Réessayez.</p>}

      {structures && (
        <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left text-muted">
                <th className="px-4 py-3 font-normal">Structure</th>
                <th className="px-4 py-3 font-normal">Type</th>
                <th className="px-4 py-3 font-normal">Point focal</th>
                <th className="px-4 py-3 font-normal">Email</th>
                <th className="px-4 py-3 font-normal">Téléphone</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {structuresPage.map((s) => {
                const focal = pointFocalPrincipal(s.uuid)
                return (
                  <tr key={s.uuid} className="border-b border-border last:border-0 hover:bg-hover">
                    <td className="px-4 py-3">
                      <span className="font-semibold">{s.sigle}</span>
                      <p className="text-xs text-muted">{s.denomination}</p>
                      {!s.actif && <span className="mt-1 inline-block rounded-full bg-nested px-2 py-0.5 text-xs text-muted">Inactive</span>}
                    </td>
                    <td className="px-4 py-3">{LIBELLES_TYPE_STRUCTURE[s.type]}</td>
                    <td className="px-4 py-3">{focal?.nomComplet ?? '—'}</td>
                    <td className="px-4 py-3">{s.email ?? '—'}</td>
                    <td className="chiffres px-4 py-3">{s.telephone ?? '—'}</td>
                    <td className="px-4 py-3 text-right">
                      {peutGerer && (
                        <div className="flex justify-end gap-1">
                          <IconeBouton
                            libelle={`Modifier ${s.sigle}`}
                            onClick={() => setStructureEnEdition(s)}
                            classeCouleur="text-muted hover:bg-accent-bg hover:text-accent"
                          >
                            <IconeModifier />
                          </IconeBouton>
                          {s.actif && (
                            <IconeBouton
                              libelle={`Supprimer ${s.sigle}`}
                              disabled={suppressionEnCours === s.uuid}
                              onClick={async () => {
                        if (await confirmer({ titre: `Supprimer ${s.sigle}`, message: `La structure ${s.sigle} sera retirée de la liste des parties prenantes.`, action: 'Supprimer' })) {
                          void supprimer(s)
                        }
                      }}
                              classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                            >
                              <IconeSupprimer />
                            </IconeBouton>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                )
              })}
              {structuresPage.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-6 text-center text-muted">
                    Aucune structure ne correspond à ces filtres.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
          <Pagination
            page={page}
            totalPages={totalPages}
            total={structuresFiltrees.length}
            taille={TAILLE_PAGE}
            onChangerPage={definirPage}
          />
        </div>
      )}

      {modaleCreation && <StructureModale onFermer={() => setModaleCreation(false)} />}
      {structureEnEdition && <StructureModale structure={structureEnEdition} onFermer={() => setStructureEnEdition(null)} />}
    </div>
  )
}
