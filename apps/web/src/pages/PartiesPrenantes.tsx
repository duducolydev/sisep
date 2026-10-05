import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router'
import {
  structuresDetailQueryOptions,
  pointsFocauxQueryOptions,
  rapportsQueryOptions,
  relancerRapports,
} from '@/lib/parties-prenantes'
import { usePeut } from '@/lib/use-permissions'
import { queryClient } from '@/lib/query-client'
import { ApiError } from '@/lib/api-client'
import { urlExportRapportTrimestriel } from '@/lib/exports'
import { StructureModale } from '@/components/StructureModale'
import { LIBELLES_TYPE_STRUCTURE } from '@/lib/types-structure'
import { IconeRecherche, IconeMettreAJour, IconePlus } from '@/components/Icones'
import { BoutonExporter } from '@/components/BoutonExporter'
import { Pagination } from '@/components/Pagination'
import { trancherPage } from '@/lib/pagination'

const TRIMESTRE_COURANT = `T${Math.floor(new Date().getMonth() / 3) + 1}`
const TAILLE_PAGE = 12

export function PartiesPrenantes() {
  const { data: structures } = useQuery(structuresDetailQueryOptions())
  const { data: pointsFocaux } = useQuery(pointsFocauxQueryOptions())
  const { data: rapports } = useQuery(rapportsQueryOptions())
  const peutGerer = usePeut('structures.gerer')
  const peutRelancer = usePeut('points_focaux.gerer')
  const peutExporter = usePeut('exports.generer')

  const [searchParams, setSearchParams] = useSearchParams()
  const [modaleOuverte, setModaleOuverte] = useState(false)
  const [relanceEnCours, setRelanceEnCours] = useState(false)
  const [messageRelance, setMessageRelance] = useState<string | null>(null)
  const [periodeExport, setPeriodeExport] = useState(TRIMESTRE_COURANT)

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

  const retardataires = (rapports?.donnees ?? []).filter((r) => r.enRetard)
  const structuresEnRetard = [...new Set(retardataires.map((r) => r.structure.uuid))]
  const rapportsRecus = (rapports?.donnees ?? []).filter((r) => 'attendu' !== r.statut).length

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

  async function relancerLesRetardataires() {
    setMessageRelance(null)
    setRelanceEnCours(true)
    try {
      const resultat = await relancerRapports(structuresEnRetard)
      const detail =
        resultat.sansDestinataire > 0
          ? ` — ${resultat.courriels} courriel(s) envoyé(s), ${resultat.sansDestinataire} sans adresse de destination`
          : ` — ${resultat.courriels} courriel(s) envoyé(s)`
      setMessageRelance(`${resultat.relances} rapport(s) relancé(s)${detail}.`)
      await queryClient.invalidateQueries({ queryKey: ['rapports'] })
    } catch (e) {
      setMessageRelance(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setRelanceEnCours(false)
    }
  }

  return (
    <div className="flex flex-col gap-8">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="font-titre text-eyebrow uppercase tracking-wide">Réseau du projet</p>
          <h1 className="text-2xl">Parties prenantes et points focaux</h1>
          <p className="text-muted">
            Directions et cellules de l'ANSD, partenaires nationaux et techniques, école de formation et bailleur — § 2.5.
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-3">
          {peutExporter && (
            <div className="flex items-center gap-1.5">
              <select
                aria-label="Période du rapport trimestriel"
                value={periodeExport}
                onChange={(e) => setPeriodeExport(e.target.value)}
                className="rounded-[var(--radius-controle)] border border-border bg-surface px-2 py-2 text-sm"
              >
                {['T1', 'T2', 'T3', 'T4'].map((p) => (
                  <option key={p} value={p}>
                    {p}
                  </option>
                ))}
              </select>
              <BoutonExporter href={urlExportRapportTrimestriel(periodeExport)} libelle="Rapport PDF" />
            </div>
          )}
          {peutRelancer && (
            <button
              type="button"
              onClick={relancerLesRetardataires}
              disabled={relanceEnCours || structuresEnRetard.length === 0}
              className="flex items-center gap-1.5 rounded-[var(--radius-controle)] border border-border bg-surface px-4 py-2 font-semibold disabled:opacity-50"
            >
              <IconeMettreAJour />
              {relanceEnCours ? 'Relance…' : 'Relancer les retardataires'}
            </button>
          )}
          {peutGerer && (
            <button
              type="button"
              onClick={() => setModaleOuverte(true)}
              className="flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d"
            >
              <IconePlus />
              Ajouter une structure
            </button>
          )}
        </div>
      </div>

      {messageRelance && (
        <div role="status" className="rounded-[var(--radius-controle)] bg-accent-bg px-4 py-3 text-accent">
          {messageRelance}
        </div>
      )}

      <section className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
        <CarteKpi libelle="Structures" valeur={structures?.donnees.length ?? 0} sousTexte="parties prenantes du projet" />
        <CarteKpi
          libelle="Points focaux désignés"
          valeur={pointsFocaux?.donnees.length ?? 0}
          sousTexte={structures ? `sur ${structures.donnees.length} structures` : undefined}
        />
        <CarteKpi
          libelle="Rapports reçus"
          valeur={rapportsRecus}
          sousTexte={rapports ? `sur ${rapports.donnees.length} attendus` : undefined}
        />
        <CarteKpi libelle="En retard" valeur={structuresEnRetard.length} sousTexte="structures à relancer" couleur={structuresEnRetard.length > 0 ? 'bad' : undefined} />
      </section>

      <section>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">Structures</h2>
          <div className="flex flex-wrap gap-3" role="search" aria-label="Filtres des structures">
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
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {structuresPage.map((s) => {
            const focal = pointsFocaux?.donnees.find((p) => p.structure.uuid === s.uuid && p.principal)
              ?? pointsFocaux?.donnees.find((p) => p.structure.uuid === s.uuid)
            const enRetard = structuresEnRetard.includes(s.uuid)

            return (
              <Link
                key={s.uuid}
                to={`/parties-prenantes/${s.uuid}`}
                className="rounded-[var(--radius-carte)] border border-border bg-surface p-4 shadow-[var(--shadow-carte)] hover:border-accent"
              >
                <p className="text-[10px] font-semibold uppercase tracking-[.14em] text-eyebrow">{LIBELLES_TYPE_STRUCTURE[s.type]}</p>
                <p className="font-titre text-base">{s.sigle}</p>
                <p className="text-sm text-muted">{s.denomination}</p>
                <p className="min-h-5 truncate text-xs text-muted">{s.email ?? '—'}</p>
                <div className="mt-3 flex items-center justify-between border-t border-border pt-2.5 text-xs text-muted">
                  <span>{focal ? focal.nomComplet : 'Point focal non désigné'}</span>
                  {enRetard && <span className="rounded-full bg-bad-bg px-2 py-0.5 font-medium text-bad">Rapport en retard</span>}
                </div>
              </Link>
            )
          })}
          {structuresPage.length === 0 && <p className="text-muted">Aucune structure ne correspond à ces filtres.</p>}
        </div>
        <Pagination page={page} totalPages={totalPages} total={structuresFiltrees.length} taille={TAILLE_PAGE} onChangerPage={definirPage} />
      </section>

      {modaleOuverte && <StructureModale onFermer={() => setModaleOuverte(false)} />}
    </div>
  )
}

function CarteKpi({
  libelle,
  valeur,
  sousTexte,
  couleur,
}: {
  libelle: string
  valeur: number
  sousTexte?: string
  couleur?: 'bad'
}) {
  return (
    <div className="relative overflow-hidden rounded-[var(--radius-carte)] bg-surface p-4 pl-5 shadow-[var(--shadow-carte)]">
      <span className={`absolute inset-y-0 left-0 w-[3px] ${couleur === 'bad' ? 'bg-bad' : 'bg-accent'}`} aria-hidden="true" />
      <p className="text-[10.5px] font-semibold uppercase tracking-[.13em] text-muted">{libelle}</p>
      <p className="chiffres mt-1.5 mb-0.5 text-[26px] font-extrabold tracking-tight">{valeur}</p>
      {sousTexte && <p className="text-xs text-muted">{sousTexte}</p>}
    </div>
  )
}
