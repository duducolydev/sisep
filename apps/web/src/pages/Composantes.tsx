import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { archiverElementPtba, arbreQueryOptions, projetCourantQueryOptions, structuresQueryOptions, type NiveauPtba } from '@/lib/composantes'
import { filtrerArbre, type FiltresArbre } from '@/lib/arbre-filtrage'
import { ArbreExecution, type CibleArbre } from '@/components/ArbreExecution'
import { ElementPtbaModale } from '@/components/ElementPtbaModale'
import { IconeBouton, IconeModifier, IconeSupprimer } from '@/components/Icones'
import { useConfirmer } from '@/lib/confirmation'
import { useToast } from '@/components/ToastProvider'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
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
  const peutModifier = usePeut('activites.modifier')
  const peutArchiver = usePeut('activites.supprimer')
  const confirmer = useConfirmer()
  const afficherToast = useToast()
  const [edition, setEdition] = useState<{ niveau: NiveauPtba; uuid?: string; parentUuid?: string; prefixeCode?: string } | null>(null)
  const [erreurGestion, setErreurGestion] = useState<string | null>(null)

  async function archiver(niveau: NiveauPtba, uuid: string, intitule: string) {
    const noms = { composante: 'la composante', sous_composante: 'la sous-composante', activite: "l'activité" }
    if (
      !(await confirmer({
        titre: `Archiver ${noms[niveau]}`,
        message: `« ${intitule} » sera retirée de l'arbre. Elle reste en base et dans le journal ; l'archivage est refusé si elle contient encore des éléments ou porte un budget.`,
        action: 'Archiver',
      }))
    ) {
      return
    }
    setErreurGestion(null)
    try {
      await archiverElementPtba(niveau, uuid)
      await queryClient.invalidateQueries({ queryKey: ['arbre'] })
      afficherToast('Élément archivé.')
    } catch (e) {
      setErreurGestion(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  /** Boutons de gestion d'une ligne de l'arbre — selon les droits de l'utilisateur. */
  function actionsArbre(cible: CibleArbre) {
    const { niveau, noeud } = cible
    return (
      <>
        {peutCreer && niveau === 'composante' && (
          <IconeBouton
            libelle={`Ajouter une sous-composante à ${noeud.code}`}
            onClick={() => setEdition({ niveau: 'sous_composante', parentUuid: noeud.uuid, prefixeCode: `${noeud.code}.` })}
          >
            <IconePlus />
          </IconeBouton>
        )}
        {peutCreer && niveau === 'sous_composante' && (
          <IconeBouton libelle={`Ajouter une activité à ${noeud.code}`} onClick={() => setModaleOuverte(true)}>
            <IconePlus />
          </IconeBouton>
        )}
        {(niveau === 'activite' ? peutModifier : peutCreer) && (
          <IconeBouton libelle={`Modifier ${noeud.code}`} onClick={() => setEdition({ niveau, uuid: noeud.uuid })}>
            <IconeModifier />
          </IconeBouton>
        )}
        {peutArchiver && (
          <IconeBouton
            libelle={`Archiver ${noeud.code}`}
            onClick={() => void archiver(niveau, noeud.uuid, `${noeud.code} — ${noeud.libelle}`)}
            classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
          >
            <IconeSupprimer />
          </IconeBouton>
        )}
      </>
    )
  }
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
            onClick={() => setEdition({ niveau: 'composante' })}
            className="flex items-center gap-1.5 rounded-[var(--radius-controle)] border border-border bg-surface px-4 py-2 font-semibold transition-colors hover:border-accent hover:text-accent"
          >
            <IconePlus />
            Nouvelle composante
          </button>
        )}
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
          {erreurGestion && (
            <div role="alert" className="mb-3 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
              {erreurGestion}
            </div>
          )}
          <ArbreExecution
            composantes={composantesFiltrees}
            tauxAttendu={arbre.tauxAttendu}
            toutDeplie={Boolean(filtres.recherche?.trim())}
            actions={peutCreer || peutModifier || peutArchiver ? actionsArbre : undefined}
          />
        </>
      )}

      {edition && (
        <ElementPtbaModale
          niveau={edition.niveau}
          uuid={edition.uuid}
          parentUuid={edition.parentUuid}
          prefixeCode={edition.prefixeCode}
          onFermer={() => setEdition(null)}
        />
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
