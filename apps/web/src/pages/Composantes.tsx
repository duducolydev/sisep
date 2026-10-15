import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { arbreQueryOptions, projetCourantQueryOptions, structuresQueryOptions } from '@/lib/composantes'
import { filtrerArbre, type FiltresArbre } from '@/lib/arbre-filtrage'
import { ArbreExecution } from '@/components/ArbreExecution'
import { VueEnsembleComposantes } from '@/components/VueEnsembleComposantes'
import { usePeut, useUtilisateurCourant } from '@/lib/use-permissions'
import { CreerActiviteModale } from '@/components/CreerActiviteModale'
import { IconePlus, IconeComposante, IconeStatut, IconeStructure, IconeRecherche } from '@/components/Icones'
import { Squelette } from '@/components/Squelette'
import { SelecteurExercice } from '@/components/SelecteurExercice'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'

const STATUTS = [
  { valeur: 'planifiee', libelle: 'Planifiée' },
  { valeur: 'en_cours', libelle: 'En cours' },
  { valeur: 'terminee', libelle: 'Terminée' },
  { valeur: 'suspendue', libelle: 'Suspendue' },
  { valeur: 'annulee', libelle: 'Annulée' },
]

export function Composantes() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [modaleOuverte, setModaleOuverte] = useState(false)
  const peutCreer = usePeut('activites.creer')
  const peutExporter = usePeut('exports.generer')
  const { data: utilisateur } = useUtilisateurCourant()

  const { data: projet } = useQuery(projetCourantQueryOptions())
  // L'exercice vit dans l'URL comme les autres filtres : un lien vers une
  // année précise doit être partageable (§ 8.1).
  const exercice = searchParams.get('exercice') ? Number(searchParams.get('exercice')) : undefined
  const { data: arbre, isLoading, isError } = useQuery(arbreQueryOptions(projet?.uuid, 4, exercice))
  const { data: structures } = useQuery(structuresQueryOptions())

  const mesActivites = searchParams.get('mes') === '1'

  const filtres: FiltresArbre = {
    composante: searchParams.get('composante'),
    statut: searchParams.get('statut'),
    structure: mesActivites ? (utilisateur?.structureUuid ?? null) : searchParams.get('structure'),
    recherche: searchParams.get('q'),
  }

  function definirFiltre(cle: keyof FiltresArbre, valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') {
      suivant.delete(cle === 'recherche' ? 'q' : cle)
    } else {
      suivant.set(cle === 'recherche' ? 'q' : cle, valeur)
    }
    suivant.delete('mes')
    setSearchParams(suivant, { replace: true })
  }

  function basculerMesActivites() {
    const suivant = new URLSearchParams(searchParams)
    if (mesActivites) {
      suivant.delete('mes')
    } else {
      suivant.set('mes', '1')
      suivant.delete('structure')
    }
    setSearchParams(suivant, { replace: true })
  }

  const composantesFiltrees = useMemo(
    () => (arbre ? filtrerArbre(arbre.composantes, filtres) : []),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [arbre, filtres.composante, filtres.statut, filtres.structure, filtres.recherche],
  )

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl">Composantes</h1>
          <p className="text-muted">Arbre composante → sous-composante → activité → sous-activité — § 2.2.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
        {peutExporter && <BoutonExporter href={urlExportDonnees('composantes', { exercice: searchParams.get('exercice') })} libelle="Exporter" />}
        {peutCreer && (
          <button
            type="button"
            onClick={() => setModaleOuverte(true)}
            className="flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d"
          >
            <IconePlus />
            Créer une activité
          </button>
        )}
        </div>
      </div>

      <div
        className="mb-6 flex flex-wrap gap-3 rounded-[var(--radius-carte)] bg-surface p-3 shadow-[var(--shadow-carte)]"
        role="search"
        aria-label="Filtres de l'arbre"
      >
        <div className="relative">
          <span
            className={`pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 ${filtres.composante ? 'text-accent' : 'text-muted'}`}
          >
            <IconeComposante />
          </span>
          <select
            aria-label="Composante"
            value={filtres.composante ?? ''}
            onChange={(e) => definirFiltre('composante', e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          >
            <option value="">Toutes les composantes</option>
            {arbre?.composantes.map((c) => (
              <option key={c.uuid} value={c.uuid}>
                {c.code} — {c.libelle}
              </option>
            ))}
          </select>
        </div>

        <div className="relative">
          <span className={`pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 ${filtres.statut ? 'text-accent' : 'text-muted'}`}>
            <IconeStatut />
          </span>
          <select
            aria-label="Statut"
            value={filtres.statut ?? ''}
            onChange={(e) => definirFiltre('statut', e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          >
            <option value="">Tous les statuts</option>
            {STATUTS.map((s) => (
              <option key={s.valeur} value={s.valeur}>
                {s.libelle}
              </option>
            ))}
          </select>
        </div>

        <div className="relative">
          <span
            className={`pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 ${!mesActivites && filtres.structure ? 'text-accent' : 'text-muted'}`}
          >
            <IconeStructure />
          </span>
          <select
            aria-label="Structure responsable"
            value={mesActivites ? '' : (filtres.structure ?? '')}
            disabled={mesActivites}
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

        <div className="relative min-w-48 flex-1">
          <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted">
            <IconeRecherche />
          </span>
          <input
            type="search"
            aria-label="Recherche"
            placeholder="Rechercher une activité ou une ligne (RNOCB…)"
            value={filtres.recherche ?? ''}
            onChange={(e) => definirFiltre('recherche', e.target.value)}
            className="w-full rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          />
        </div>

        <SelecteurExercice projetUuid={projet?.uuid} />

        {utilisateur?.structureUuid && (
          <button
            type="button"
            onClick={basculerMesActivites}
            aria-pressed={mesActivites}
            className={`rounded-[var(--radius-controle)] border px-3 py-2 font-semibold transition-colors ${
              mesActivites ? 'border-accent bg-accent-bg text-accent' : 'border-border bg-surface hover:border-accent'
            }`}
          >
            Mes activités
          </button>
        )}
      </div>

      {isLoading && <Squelette lignes={4} />}
      {isError && <p className="text-bad">Impossible de charger l'arbre. Réessayez.</p>}
      {arbre && (
        <>
          {/* Les graphiques suivent les filtres ci-dessus : résumer un périmètre
              différent de celui de l'arbre en dessous serait trompeur. */}
          <VueEnsembleComposantes composantes={composantesFiltrees} tauxAttendu={arbre.tauxAttendu} />
          <ArbreExecution composantes={composantesFiltrees} tauxAttendu={arbre.tauxAttendu} toutDeplie={Boolean(filtres.recherche?.trim())} />
        </>
      )}

      {modaleOuverte && projet && (
        <CreerActiviteModale
          projetUuid={projet.uuid}
          composantes={arbre?.composantes ?? []}
          structures={structures?.donnees ?? []}
          onFermer={() => setModaleOuverte(false)}
        />
      )}
    </div>
  )
}
