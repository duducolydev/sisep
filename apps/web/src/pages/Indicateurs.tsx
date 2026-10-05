import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams, Link, useNavigate } from 'react-router'
import { indicateursQueryOptions, type TypeIndicateur } from '@/lib/indicateurs'
import { budgetExecutionQueryOptions } from '@/lib/budget'
import { structuresQueryOptions } from '@/lib/composantes'
import { urlExportCadreResultats } from '@/lib/exports'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { AtteinteGlobaleIndicateurs } from '@/components/AtteinteGlobaleIndicateurs'
import { formaterValeurIndicateur } from '@/lib/format'
import { usePeut, useUtilisateurCourant } from '@/lib/use-permissions'
import { DefinirIndicateurModale } from '@/components/DefinirIndicateurModale'
import { ValeurIndicateurInline } from '@/components/ValeurIndicateurInline'
import { BoutonExporter } from '@/components/BoutonExporter'
import { Pagination } from '@/components/Pagination'
import { Squelette } from '@/components/Squelette'
import { IconeStatut, IconeComposante, IconeStructure } from '@/components/Icones'
import { trancherPage } from '@/lib/pagination'

const TAILLE_PAGE = 15

export function Indicateurs() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const [modaleOuverte, setModaleOuverte] = useState(false)
  const peutDefinir = usePeut('indicateurs.definir')
  const peutExporter = usePeut('exports.generer')
  const { data: utilisateur } = useUtilisateurCourant()

  const type = (searchParams.get('type') as TypeIndicateur | null) ?? undefined
  const composanteUuid = searchParams.get('composante') ?? undefined
  const mesIndicateurs = searchParams.get('mes') === '1'
  const structureUuid = mesIndicateurs ? (utilisateur?.structureUuid ?? undefined) : (searchParams.get('structure') ?? undefined)
  const page = Math.max(1, Number(searchParams.get('page') ?? '1'))

  const { data: indicateurs, isLoading, isError } = useQuery(indicateursQueryOptions(type, composanteUuid, structureUuid))
  const { data: composantes } = useQuery(budgetExecutionQueryOptions('composante'))
  const { data: structures } = useQuery(structuresQueryOptions())

  function definirFiltre(cle: 'type' | 'composante' | 'structure', valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') {
      suivant.delete(cle)
    } else {
      suivant.set(cle, valeur)
    }
    suivant.delete('mes')
    suivant.delete('page')
    setSearchParams(suivant, { replace: true })
  }

  function basculerMesIndicateurs() {
    const suivant = new URLSearchParams(searchParams)
    if (mesIndicateurs) {
      suivant.delete('mes')
    } else {
      suivant.set('mes', '1')
      suivant.delete('structure')
    }
    suivant.delete('page')
    setSearchParams(suivant, { replace: true })
  }

  function definirPage(suivante: number) {
    const suivant = new URLSearchParams(searchParams)
    suivant.set('page', String(suivante))
    setSearchParams(suivant, { replace: true })
  }

  const indicateursPage = useMemo(() => trancherPage(indicateurs?.donnees ?? [], page, TAILLE_PAGE), [indicateurs, page])
  const totalPages = Math.max(1, Math.ceil((indicateurs?.donnees.length ?? 0) / TAILLE_PAGE))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl">Indicateurs de performance</h1>
          <p className="text-muted">Indicateurs de performance IOD et IRI du cadre de résultats — § 2.4.</p>
        </div>
        <div className="flex shrink-0 items-center gap-3">
          {peutExporter && <BoutonExporter href={urlExportCadreResultats()} libelle="Exporter" />}
          {peutDefinir && (
            <button
              type="button"
              onClick={() => setModaleOuverte(true)}
              className="rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d"
            >
              Définir un indicateur
            </button>
          )}
        </div>
      </div>

      <div
        className="mb-6 flex flex-wrap gap-3 rounded-[var(--radius-carte)] bg-surface p-3 shadow-[var(--shadow-carte)]"
        role="search"
        aria-label="Filtres des indicateurs de performance"
      >
        <div className="relative">
          <span className={`pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 ${type ? 'text-accent' : 'text-muted'}`}>
            <IconeStatut />
          </span>
          <select
            aria-label="Type"
            value={type ?? ''}
            onChange={(e) => definirFiltre('type', e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          >
            <option value="">Tous les types</option>
            <option value="IOD">IOD — objectif de développement</option>
            <option value="IRI">IRI — résultat intermédiaire</option>
          </select>
        </div>

        <div className="relative">
          <span className={`pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 ${composanteUuid ? 'text-accent' : 'text-muted'}`}>
            <IconeComposante />
          </span>
          <select
            aria-label="Composante"
            value={composanteUuid ?? ''}
            onChange={(e) => definirFiltre('composante', e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          >
            <option value="">Toutes les composantes</option>
            {composantes?.donnees.map((c) => (
              <option key={c.uuid} value={c.uuid}>
                {c.code} — {c.libelle}
              </option>
            ))}
          </select>
        </div>

        <div className="relative">
          <span
            className={`pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 ${!mesIndicateurs && structureUuid ? 'text-accent' : 'text-muted'}`}
          >
            <IconeStructure />
          </span>
          <select
            aria-label="Structure responsable"
            value={mesIndicateurs ? '' : (structureUuid ?? '')}
            disabled={mesIndicateurs}
            onChange={(e) => definirFiltre('structure', e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9 disabled:opacity-50"
          >
            <option value="">Toutes les structures</option>
            {structures?.donnees.map((s) => (
              <option key={s.uuid} value={s.uuid}>
                {s.sigle}
              </option>
            ))}
          </select>
        </div>

        {utilisateur?.structureUuid && (
          <button
            type="button"
            onClick={basculerMesIndicateurs}
            aria-pressed={mesIndicateurs}
            className={`rounded-[var(--radius-controle)] border px-3 py-2 font-semibold transition-colors ${
              mesIndicateurs ? 'border-accent bg-accent-bg text-accent' : 'border-border bg-surface hover:border-accent'
            }`}
          >
            Mes indicateurs de performance
          </button>
        )}
      </div>

      {isLoading && <Squelette lignes={4} />}
      {isError && <p className="text-bad">Impossible de charger les indicateurs de performance. Réessayez.</p>}

      {indicateurs && indicateurs.donnees.length > 0 && (
        <section className="mb-6 rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
          <h2 className="mb-1 text-lg font-semibold">Vue d'ensemble</h2>
          <p className="mb-3 text-sm text-muted">
            Position de chaque indicateur entre sa référence (0 %) et sa cible (100 %), toutes unités confondues.
          </p>
          <AtteinteGlobaleIndicateurs indicateurs={indicateurs.donnees} />
        </section>
      )}

      {indicateurs && (
        <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left text-muted">
                <th className="px-4 py-3 font-normal">Indicateur</th>
                <th className="px-4 py-3 font-normal">Référence</th>
                <th className="px-4 py-3 text-right font-normal">Actuel</th>
                <th className="px-4 py-3 text-right font-normal">Cible</th>
                <th className="px-4 py-3 font-normal">Taux d'atteinte</th>
              </tr>
            </thead>
            <tbody>
              {indicateursPage.map((i) => (
                <tr
                  key={i.uuid}
                  onClick={(e) => {
                    if ((e.target as HTMLElement).closest('a, button')) return
                    void navigate(`/indicateurs/${i.uuid}`)
                  }}
                  className="cursor-pointer border-b border-border last:border-0 hover:bg-hover"
                >
                  <td className="px-4 py-3">
                    <Link to={`/indicateurs/${i.uuid}`} className="text-accent">
                      <span className="chiffres mr-2 text-muted">{i.code}</span>
                      {i.libelle}
                    </Link>
                    <p className="text-xs text-muted">{i.type === 'IOD' ? "Objectif de développement" : 'Résultat intermédiaire'}</p>
                  </td>
                  <td className="chiffres px-4 py-3 text-muted">
                    {formaterValeurIndicateur(i.valeurReference)} {i.unite}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <ValeurIndicateurInline
                      indicateurUuid={i.uuid}
                      actuel={i.actuel}
                      unite={i.unite}
                      peutSaisir={i.peutSaisir}
                    />
                  </td>
                  <td className="chiffres px-4 py-3 text-right">
                    {formaterValeurIndicateur(i.valeurCible)} {i.unite}
                  </td>
                  <td className="px-4 py-3">
                    <BarreAtteinte taux={i.tauxAtteinte} />
                  </td>
                </tr>
              ))}
              {indicateursPage.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-4 py-6 text-center text-muted">
                    Aucun indicateur ne correspond à ces filtres.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
          <Pagination
            page={page}
            totalPages={totalPages}
            total={indicateurs.donnees.length}
            taille={TAILLE_PAGE}
            onChangerPage={definirPage}
          />
        </div>
      )}

      {modaleOuverte && (
        <DefinirIndicateurModale composantes={composantes?.donnees ?? []} onFermer={() => setModaleOuverte(false)} />
      )}
    </div>
  )
}
