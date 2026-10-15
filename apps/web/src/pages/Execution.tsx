import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { projetCourantQueryOptions } from '@/lib/composantes'
import { executionQueryOptions, type LigneExecutionSynthese } from '@/lib/execution'
import { formaterDate, formaterFcfa, formaterTaux } from '@/lib/format'
import { SelecteurExercice } from '@/components/SelecteurExercice'
import { Squelette } from '@/components/Squelette'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'
import { usePeut } from '@/lib/use-permissions'
import { ComboboxSimple } from '@/components/ComboboxSimple'

const MOIS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre']

const LIBELLES_NIVEAU = {
  composante: 'Par composante',
  activite: 'Par activité',
  ligne: 'Par ligne du PTBA',
} as const

/**
 * Exécution physique et financière (retours des RSE, octobre 2026) : lignes
 * réalisées sur le total, engagements et dépenses sur le budget, filtrables
 * par période (année, trimestre, mois) et par composante ou activité. Tout
 * l'état vit dans l'URL, comme sur les autres écrans de pilotage.
 */
export function Execution() {
  const [searchParams, setSearchParams] = useSearchParams()
  const { data: projet } = useQuery(projetCourantQueryOptions())

  const granularite = searchParams.get('trimestre') ? 'trimestre' : searchParams.get('mois') ? 'mois' : 'annee'
  const filtres = {
    exercice: searchParams.get('exercice') ?? undefined,
    trimestre: searchParams.get('trimestre') ?? undefined,
    mois: searchParams.get('mois') ?? undefined,
    composante: searchParams.get('composante') ?? undefined,
    activite: searchParams.get('activite') ?? undefined,
    ligne: searchParams.get('ligne') ?? undefined,
  }
  const { data, isLoading, isError } = useQuery(executionQueryOptions(projet?.uuid, filtres))
  const peutExporter = usePeut('exports.generer')

  function definir(modifications: Record<string, string | null>) {
    const suivant = new URLSearchParams(searchParams)
    for (const [cle, valeur] of Object.entries(modifications)) {
      if (valeur === null || valeur === '') suivant.delete(cle)
      else suivant.set(cle, valeur)
    }
    setSearchParams(suivant, { replace: true })
  }

  function changerGranularite(suivante: 'annee' | 'trimestre' | 'mois') {
    const moisCourant = new Date().getMonth() + 1
    definir({
      trimestre: suivante === 'trimestre' ? String(Math.ceil(moisCourant / 3)) : null,
      mois: suivante === 'mois' ? String(moisCourant) : null,
    })
  }

  function approfondir(ligne: LigneExecutionSynthese) {
    if (data?.niveau === 'composante') definir({ composante: ligne.uuid, activite: null })
    else if (data?.niveau === 'activite') definir({ activite: ligne.uuid })
  }

  const activitesProposees = (data?.options.activites ?? []).filter(
    (a) => filtres.composante === undefined || a.composanteUuid === filtres.composante,
  )
  const sousPeriode = granularite !== 'annee'
  const t = data?.totaux

  return (
    <div className="flex flex-col gap-6">
      <div>
        <p className="font-titre text-eyebrow uppercase tracking-wide">Pilotage</p>
        <h1 className="text-2xl">Exécution physique et financière</h1>
        <p className="text-muted">
          Lignes du PTBA réalisées sur le total, engagements et dépenses rapportés au budget de l'exercice
          {data && ` — du ${formaterDate(data.periode.debut)} au ${formaterDate(data.periode.fin)}`}.
        </p>
      </div>

      <div
        role="search"
        aria-label="Filtres de l'exécution"
        className="flex flex-wrap items-center gap-3 rounded-[var(--radius-carte)] bg-surface p-3 shadow-[var(--shadow-carte)]"
      >
        <SelecteurExercice projetUuid={projet?.uuid} />
        {peutExporter && <BoutonExporter href={urlExportDonnees('execution', filtres)} libelle="Exporter" />}

        <div className="flex gap-0.5 rounded-[var(--radius-controle)] bg-nested p-0.5 text-xs" role="group" aria-label="Période">
          {(
            [
              ['annee', 'Année'],
              ['trimestre', 'Trimestre'],
              ['mois', 'Mois'],
            ] as const
          ).map(([valeur, libelle]) => (
            <button
              key={valeur}
              type="button"
              aria-pressed={granularite === valeur}
              onClick={() => changerGranularite(valeur)}
              className={`rounded-[calc(var(--radius-controle)-2px)] px-2.5 py-1.5 font-semibold transition-colors ${
                granularite === valeur ? 'bg-accent text-white' : 'text-muted hover:text-accent'
              }`}
            >
              {libelle}
            </button>
          ))}
        </div>
        {granularite === 'trimestre' && (
          <select
            aria-label="Trimestre"
            value={filtres.trimestre}
            onChange={(e) => definir({ trimestre: e.target.value })}
            className="rounded-[var(--radius-controle)] border border-border bg-surface px-2.5 py-1.5 text-sm"
          >
            {[1, 2, 3, 4].map((n) => (
              <option key={n} value={n}>
                T{n}
              </option>
            ))}
          </select>
        )}
        {granularite === 'mois' && (
          <select
            aria-label="Mois"
            value={filtres.mois}
            onChange={(e) => definir({ mois: e.target.value })}
            className="rounded-[var(--radius-controle)] border border-border bg-surface px-2.5 py-1.5 text-sm"
          >
            {MOIS.map((m, i) => (
              <option key={m} value={i + 1}>
                {m}
              </option>
            ))}
          </select>
        )}

        <select
          aria-label="Composante"
          value={filtres.composante ?? ''}
          onChange={(e) => definir({ composante: e.target.value, activite: null, ligne: null })}
          className="w-full rounded-[var(--radius-controle)] border border-border bg-surface px-2.5 py-1.5 text-sm sm:w-72"
        >
          <option value="">Toutes les composantes</option>
          {(data?.options.composantes ?? []).map((c) => (
            <option key={c.uuid} value={c.uuid}>
              {c.code} — {c.libelle}
            </option>
          ))}
        </select>

        <select
          aria-label="Activité"
          value={filtres.activite ?? ''}
          onChange={(e) => definir({ activite: e.target.value, ligne: null })}
          className="w-full rounded-[var(--radius-controle)] border border-border bg-surface px-2.5 py-1.5 text-sm sm:w-72"
        >
          <option value="">Toutes les activités</option>
          {activitesProposees.map((a) => (
            <option key={a.uuid} value={a.uuid}>
              {a.code} — {a.libelle}
            </option>
          ))}
        </select>

        {/* Recherche directe d'une ligne par son nom : RNOCB, ENES, RGE… */}
        <div className="w-full sm:w-80">
          <ComboboxSimple
            id="recherche-ligne"
            options={[
              { value: '', label: 'Toutes les lignes' },
              ...(data?.options.lignes ?? []).map((l) => ({ value: l.uuid, label: `${l.activiteCode} — ${l.libelle}` })),
            ]}
            valeur={filtres.ligne ?? ''}
            onChange={(uuid) => definir({ ligne: uuid, composante: null, activite: null })}
            placeholder="Rechercher une ligne (RNOCB, ENES…)"
          />
        </div>
      </div>

      {isLoading && <Squelette lignes={5} />}
      {isError && <p className="text-bad">Impossible de charger l'exécution. Réessayez.</p>}

      {t && data && (
        <>
          <section className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
            <Tuile
              libelle="Exécution physique"
              valeur={formaterTaux(t.tauxPhysique)}
              detail={`${t.realisees} / ${t.unites} lignes réalisées`}
              couleur="bg-success"
            />
            <Tuile
              libelle="Avancement moyen des étapes"
              valeur={formaterTaux(t.avancementMoyen)}
              detail="Lignes structurées en étapes"
              couleur="bg-accent-clair"
            />
            <Tuile
              libelle="Taux d'engagement"
              valeur={formaterTaux(t.tauxEngagement)}
              detail={`${formaterFcfa(t.engage)} engagés sur ${formaterFcfa(t.budget)}`}
              couleur="bg-eyebrow"
            />
            <Tuile
              libelle="Exécution financière"
              valeur={formaterTaux(t.tauxExecution)}
              detail={
                sousPeriode
                  ? `${formaterFcfa(t.depense)} dépensés, dont ${formaterFcfa(t.depensePeriode)} sur la période`
                  : `${formaterFcfa(t.depense)} dépensés sur ${formaterFcfa(t.budget)}`
              }
              couleur="bg-accent"
            />
          </section>

          <section className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-lg font-semibold">{LIBELLES_NIVEAU[data.niveau]}</h2>
              <ul className="flex flex-wrap gap-4 text-xs text-muted" aria-label="Légende">
                <li className="flex items-center gap-1.5">
                  <span className="h-2.5 w-2.5 rounded-sm bg-success" aria-hidden="true" /> Exécution physique
                </li>
                <li className="flex items-center gap-1.5">
                  <span className="h-2.5 w-2.5 rounded-sm bg-accent" aria-hidden="true" /> Exécution financière (dépensé / budget)
                </li>
              </ul>
            </div>
            {data.lignes.length === 0 ? (
              <p className="text-muted">Aucune activité dans ce périmètre.</p>
            ) : (
              <ul className="flex flex-col gap-3">
                {data.lignes.map((l) => (
                  <li key={l.uuid}>
                    <button
                      type="button"
                      disabled={data.niveau === 'ligne'}
                      onClick={() => approfondir(l)}
                      className="grid w-full grid-cols-1 gap-1.5 rounded-[var(--radius-controle)] p-1.5 text-left transition-colors enabled:hover:bg-hover sm:grid-cols-[minmax(0,16rem)_1fr] sm:gap-4"
                      title={data.niveau === 'ligne' ? undefined : 'Voir le détail'}
                    >
                      <span className="min-w-0 text-sm">
                        {data.niveau !== 'ligne' && <span className="chiffres mr-1.5 text-muted">{l.code}</span>}
                        <span className="line-clamp-2">{l.libelle}</span>
                      </span>
                      <span className="flex flex-col gap-1">
                        <Barre taux={l.tauxPhysique} classe="bg-success" libelle="Exécution physique" />
                        <Barre taux={l.tauxExecution} classe="bg-accent" libelle="Exécution financière" />
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
            <table className="w-full min-w-[1080px] text-sm">
              <thead>
                <tr className="border-b border-border text-left text-muted">
                  <th className="px-4 py-3 font-normal">{LIBELLES_NIVEAU[data.niveau].replace('Par ', '').replace(/^./, (c) => c.toUpperCase())}</th>
                  <th className="px-4 py-3 text-right font-normal">Lignes réalisées</th>
                  <th className="px-4 py-3 text-right font-normal">Exéc. physique</th>
                  <th className="px-4 py-3 text-right font-normal">Avancement</th>
                  <th className="px-4 py-3 text-right font-normal">Budget</th>
                  <th className="px-4 py-3 text-right font-normal">Engagé</th>
                  <th className="px-4 py-3 text-right font-normal">Dépensé</th>
                  <th className="px-4 py-3 text-right font-normal">Taux d'engagement</th>
                  <th className="px-4 py-3 text-right font-normal">Exéc. financière</th>
                </tr>
              </thead>
              <tbody>
                {data.lignes.map((l) => (
                  <tr key={l.uuid} className="border-b border-border last:border-0">
                    <td className="px-4 py-2.5">
                      {data.niveau !== 'ligne' && <span className="chiffres mr-1.5 text-muted">{l.code}</span>}
                      {l.libelle}
                    </td>
                    <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">
                      {l.realisees} / {l.unites}
                    </td>
                    <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterTaux(l.tauxPhysique)}</td>
                    <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterTaux(l.avancementMoyen)}</td>
                    <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterFcfa(l.budget)}</td>
                    <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterFcfa(l.engage)}</td>
                    <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterFcfa(l.depense)}</td>
                    <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterTaux(l.tauxEngagement)}</td>
                    <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterTaux(l.tauxExecution)}</td>
                  </tr>
                ))}
                <tr className="bg-nested font-semibold">
                  <td className="px-4 py-2.5">Total</td>
                  <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">
                    {t.realisees} / {t.unites}
                  </td>
                  <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterTaux(t.tauxPhysique)}</td>
                  <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterTaux(t.avancementMoyen)}</td>
                  <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterFcfa(t.budget)}</td>
                  <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterFcfa(t.engage)}</td>
                  <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterFcfa(t.depense)}</td>
                  <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterTaux(t.tauxEngagement)}</td>
                  <td className="chiffres px-4 py-2.5 text-right whitespace-nowrap">{formaterTaux(t.tauxExecution)}</td>
                </tr>
              </tbody>
            </table>
          </section>
          <p className="text-xs text-muted">
            Une ligne est réalisée quand toutes ses étapes sont à 100 %, à la date de leur dernière mise à jour ; une ligne
            sans étape compte dans le total. Engagements et dépenses validés, cumulés depuis le début de l'exercice jusqu'à la
            fin de la période.
          </p>
        </>
      )}
    </div>
  )
}

function Tuile({ libelle, valeur, detail, couleur }: { libelle: string; valeur: string; detail: string; couleur: string }) {
  return (
    <div className="relative min-w-0 overflow-hidden rounded-[var(--radius-carte)] bg-surface p-4 pl-5 shadow-[var(--shadow-carte)]">
      <span className={`absolute inset-y-0 left-0 w-[3px] ${couleur}`} aria-hidden="true" />
      <p className="truncate text-[10.5px] font-semibold uppercase tracking-[.13em] text-muted">{libelle}</p>
      <p className="chiffres mt-1.5 mb-0.5 text-[26px] font-extrabold tracking-tight">{valeur}</p>
      <p className="text-xs text-muted">{detail}</p>
    </div>
  )
}

/** Barre horizontale 0-100 % ; un taux nul (division par zéro) affiche un tiret, pas une barre vide. */
function Barre({ taux, classe, libelle }: { taux: number | null; classe: string; libelle: string }) {
  const largeur = taux === null ? 0 : Math.min(100, Math.max(0, taux * 100))

  return (
    <span className="flex items-center gap-2" title={`${libelle} : ${formaterTaux(taux)}`}>
      <span className="h-2 flex-1 overflow-hidden rounded-[var(--radius-barre)] bg-track">
        <span className={`block h-full rounded-[var(--radius-barre)] ${classe}`} style={{ width: `${largeur}%` }} />
      </span>
      <span className="chiffres w-14 text-right text-xs">{formaterTaux(taux)}</span>
    </span>
  )
}
