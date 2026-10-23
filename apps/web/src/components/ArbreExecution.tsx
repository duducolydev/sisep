import { useState, type KeyboardEvent, type ReactNode } from 'react'
import { Link } from 'react-router'
import type { NoeudActivite, NoeudComposante, NoeudSousActivite, NoeudSousComposante } from '@/lib/composantes'
import { BarreExecution } from './BarreExecution'
import { Pastille } from './Pastille'
import { SousActiviteModale } from './SousActiviteModale'
import { IconeBouton, IconeDeplierTout, IconeReplierTout, IconeVoir } from './Icones'
import { formaterTaux } from '@/lib/format'

/** Flèches gauche/droite pour replier/déplier un nœud — § 8.3 du brief (navigation clavier de l'arbre). */
function gererToucheDepliage(evenement: KeyboardEvent, ouvert: boolean, uuid: string, onBasculer: (uuid: string) => void) {
  if ('ArrowRight' === evenement.key && !ouvert) {
    evenement.preventDefault()
    onBasculer(uuid)
  } else if ('ArrowLeft' === evenement.key && ouvert) {
    evenement.preventDefault()
    onBasculer(uuid)
  }
}

interface ArbreExecutionProps {
  composantes: NoeudComposante[]
  tauxAttendu?: number | null
  /** Recherche en cours : tout est déplié, pour que la ligne trouvée soit visible sans clic. */
  toutDeplie?: boolean
  /** Boutons de gestion d'une ligne de l'arbre (modifier, archiver, ajouter) — absents en lecture seule. */
  actions?: (cible: CibleArbre) => ReactNode
}

export type CibleArbre =
  | { niveau: 'composante'; noeud: NoeudComposante }
  | { niveau: 'sous_composante'; noeud: NoeudSousComposante; composante: NoeudComposante }
  | { niveau: 'activite'; noeud: NoeudActivite; sousComposante: NoeudSousComposante }

interface SousActiviteOuverte {
  activiteUuid: string
  sousActiviteUuid: string
}

export function ArbreExecution({ composantes, tauxAttendu, toutDeplie = false, actions }: ArbreExecutionProps) {
  const [ouverts, setOuverts] = useState<Set<string>>(() => new Set(composantes.map((c) => c.uuid)))
  const [sousActiviteOuverte, setSousActiviteOuverte] = useState<SousActiviteOuverte | null>(null)

  const ouvertsEffectifs = toutDeplie ? tousLesNoeuds(composantes) : ouverts

  function basculer(uuid: string) {
    setOuverts((precedent) => {
      const suivant = new Set(precedent)
      if (suivant.has(uuid)) suivant.delete(uuid)
      else suivant.add(uuid)

      return suivant
    })
  }

  function toutDeplierOuReplier(deplier: boolean) {
    if (!deplier) {
      setOuverts(new Set())

      return
    }

    const tous = new Set<string>()
    for (const c of composantes) {
      tous.add(c.uuid)
      for (const sc of c.sousComposantes ?? []) {
        tous.add(sc.uuid)
        for (const a of sc.activites ?? []) {
          tous.add(a.uuid)
        }
      }
    }
    setOuverts(tous)
  }

  if (composantes.length === 0) {
    return <p className="text-muted">Aucune composante ne correspond aux filtres.</p>
  }

  return (
    <div role="tree" aria-label="Arbre d'exécution du projet">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
          <span className="flex items-center gap-1.5">
            <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent" aria-hidden="true" />
            Dépensé
          </span>
          <span className="flex items-center gap-1.5">
            <span className="inline-block h-2.5 w-2.5 rounded-sm bg-accent-clair" aria-hidden="true" />
            Engagé non décaissé
          </span>
          <span className="flex items-center gap-1.5">
            <span className="inline-block h-2.5 w-2.5 rounded-sm bg-track" aria-hidden="true" />
            Disponible
          </span>
          <span className="flex items-center gap-1.5">
            <span className="inline-block h-2.5 w-2.5 rounded-sm bg-success" aria-hidden="true" />
            Progression des étapes
          </span>
          {tauxAttendu !== null && tauxAttendu !== undefined && (
            <span className="flex items-center gap-1.5">
              <span className="inline-block h-2.5 w-px bg-bad" aria-hidden="true" />
              Rythme attendu ({formaterTaux(tauxAttendu)})
            </span>
          )}
        </div>
        <div className="flex gap-1">
          <IconeBouton libelle="Tout déplier" onClick={() => toutDeplierOuReplier(true)}>
            <IconeDeplierTout />
          </IconeBouton>
          <IconeBouton libelle="Tout replier" onClick={() => toutDeplierOuReplier(false)}>
            <IconeReplierTout />
          </IconeBouton>
        </div>
      </div>

      <ul className="flex flex-col gap-1">
        {composantes.map((c) => (
          <LigneComposante
            key={c.uuid}
            composante={c}
            ouvert={ouvertsEffectifs.has(c.uuid)}
            onBasculer={basculer}
            ouverts={ouvertsEffectifs}
            tauxAttendu={tauxAttendu}
            onOuvrirSousActivite={setSousActiviteOuverte}
            actions={actions}
          />
        ))}
      </ul>

      {sousActiviteOuverte && (
        <SousActiviteModale
          activiteUuid={sousActiviteOuverte.activiteUuid}
          sousActiviteUuid={sousActiviteOuverte.sousActiviteUuid}
          onFermer={() => setSousActiviteOuverte(null)}
        />
      )}
    </div>
  )
}

function LigneComposante({
  composante,
  ouvert,
  onBasculer,
  ouverts,
  tauxAttendu,
  onOuvrirSousActivite,
  actions,
}: {
  composante: NoeudComposante
  ouvert: boolean
  onBasculer: (uuid: string) => void
  ouverts: Set<string>
  tauxAttendu?: number | null
  onOuvrirSousActivite: (cible: SousActiviteOuverte) => void
  actions?: (cible: CibleArbre) => ReactNode
}) {
  return (
    <li role="treeitem" aria-expanded={ouvert}>
      <div
        className={`grid ${actions ? 'grid-cols-[1fr_220px_auto]' : 'grid-cols-[1fr_220px]'} items-center gap-4 rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]`}
      >
        <button
          type="button"
          onClick={() => onBasculer(composante.uuid)}
          onKeyDown={(e) => gererToucheDepliage(e, ouvert, composante.uuid, onBasculer)}
          className="flex items-center gap-2 rounded-[var(--radius-controle)] text-left transition-colors hover:text-accent"
        >
          <span aria-hidden="true">{ouvert ? '▾' : '▸'}</span>
          <span className="chiffres text-muted">{composante.code}</span>
          <span className="font-titre">{composante.libelle}</span>
        </button>
        <BarreExecution notifie={composante.notifie} engage={composante.engage} depense={composante.depense} rythmeAttendu={tauxAttendu} />
        {actions && <span className="flex items-center">{actions({ niveau: 'composante', noeud: composante })}</span>}
      </div>

      {ouvert && (
        <ul className="ml-6 mt-1 flex flex-col gap-1 border-l border-border pl-4">
          {(composante.sousComposantes ?? []).map((sc) => (
            <LigneSousComposante
              key={sc.uuid}
              sousComposante={sc}
              composante={composante}
              ouvert={ouverts.has(sc.uuid)}
              onBasculer={onBasculer}
              ouverts={ouverts}
              tauxAttendu={tauxAttendu}
              onOuvrirSousActivite={onOuvrirSousActivite}
              actions={actions}
            />
          ))}
        </ul>
      )}
    </li>
  )
}

function LigneSousComposante({
  sousComposante,
  composante,
  ouvert,
  onBasculer,
  ouverts,
  tauxAttendu,
  onOuvrirSousActivite,
  actions,
}: {
  sousComposante: NoeudSousComposante
  composante: NoeudComposante
  ouvert: boolean
  onBasculer: (uuid: string) => void
  ouverts: Set<string>
  tauxAttendu?: number | null
  onOuvrirSousActivite: (cible: SousActiviteOuverte) => void
  actions?: (cible: CibleArbre) => ReactNode
}) {
  return (
    <li role="treeitem" aria-expanded={ouvert}>
      <div className={`grid ${actions ? 'grid-cols-[1fr_220px_auto]' : 'grid-cols-[1fr_220px]'} items-center gap-4 rounded-[var(--radius-carte)] bg-nested px-4 py-2`}>
        <button
          type="button"
          onClick={() => onBasculer(sousComposante.uuid)}
          onKeyDown={(e) => gererToucheDepliage(e, ouvert, sousComposante.uuid, onBasculer)}
          className="flex items-center gap-2 rounded-[var(--radius-controle)] text-left transition-colors hover:text-accent"
        >
          <span aria-hidden="true">{ouvert ? '▾' : '▸'}</span>
          <span className="chiffres text-muted">{sousComposante.code}</span>
          <span>{sousComposante.libelle}</span>
        </button>
        <BarreExecution
          notifie={sousComposante.notifie}
          engage={sousComposante.engage}
          depense={sousComposante.depense}
          rythmeAttendu={tauxAttendu}
        />
        {actions && <span className="flex items-center">{actions({ niveau: 'sous_composante', noeud: sousComposante, composante })}</span>}
      </div>

      {ouvert && (sousComposante.activites ?? []).length > 0 && (
        <ul className="ml-6 mt-1 flex flex-col gap-1 border-l border-border pl-4">
          {(sousComposante.activites ?? []).map((a) => (
            <LigneActivite
              key={a.uuid}
              activite={a}
              sousComposante={sousComposante}
              ouvert={ouverts.has(a.uuid)}
              onBasculer={onBasculer}
              tauxAttendu={tauxAttendu}
              onOuvrirSousActivite={onOuvrirSousActivite}
              actions={actions}
            />
          ))}
        </ul>
      )}
    </li>
  )
}

function LigneActivite({
  activite,
  sousComposante,
  ouvert,
  onBasculer,
  tauxAttendu,
  onOuvrirSousActivite,
  actions,
}: {
  activite: NoeudActivite
  sousComposante: NoeudSousComposante
  ouvert: boolean
  onBasculer: (uuid: string) => void
  tauxAttendu?: number | null
  onOuvrirSousActivite: (cible: SousActiviteOuverte) => void
  actions?: (cible: CibleArbre) => ReactNode
}) {
  const sousActivites = activite.sousActivites ?? []

  return (
    <li role="treeitem" aria-expanded={ouvert}>
      <div
        className={`grid ${actions ? 'grid-cols-[1fr_100px_130px_220px_auto_auto]' : 'grid-cols-[1fr_100px_130px_220px_auto]'} items-center gap-4 rounded-[var(--radius-carte)] px-4 py-2 hover:bg-hover`}
      >
        <button
          type="button"
          onClick={() => onBasculer(activite.uuid)}
          onKeyDown={(e) => gererToucheDepliage(e, ouvert, activite.uuid, onBasculer)}
          className="flex min-w-0 items-center gap-2 rounded-[var(--radius-controle)] text-left transition-colors hover:text-accent"
        >
          <span aria-hidden="true">{sousActivites.length > 0 ? (ouvert ? '▾' : '▸') : ' '}</span>
          <span className="chiffres text-muted">{activite.code}</span>
          <span className="truncate">{activite.libelle}</span>
        </button>
        <Pastille statut={activite.statut} />
        <MiniBarreAvancement avancement={activite.avancement ?? null} libelle="Progression moyenne des étapes" />
        <BarreExecution notifie={activite.notifie} engage={activite.engage} depense={activite.depense} rythmeAttendu={tauxAttendu} />
        <Link
          to={`/composantes/activites/${activite.uuid}`}
          title="Voir la fiche complète"
          aria-label={`Voir la fiche complète — ${activite.libelle}`}
          className="rounded-[var(--radius-controle)] p-1.5 text-muted transition-colors hover:bg-accent-bg hover:text-accent"
        >
          <IconeVoir />
        </Link>
        {actions && <span className="flex items-center">{actions({ niveau: 'activite', noeud: activite, sousComposante })}</span>}
      </div>

      {ouvert && sousActivites.length > 0 && (
        <ul className="ml-6 mt-1 flex flex-col gap-1 border-l border-border pl-4">
          {sousActivites.map((sa) => (
            <LigneSousActivite
              key={sa.uuid}
              sousActivite={sa}
              onOuvrir={() => onOuvrirSousActivite({ activiteUuid: activite.uuid, sousActiviteUuid: sa.uuid })}
            />
          ))}
        </ul>
      )}
    </li>
  )
}

function LigneSousActivite({ sousActivite, onOuvrir }: { sousActivite: NoeudSousActivite; onOuvrir: () => void }) {
  return (
    <li role="treeitem">
      <button
        type="button"
        onClick={onOuvrir}
        className="grid w-full grid-cols-[1fr_100px_160px] items-center gap-4 rounded-[var(--radius-carte)] px-4 py-2 text-left text-sm hover:bg-hover"
      >
        <span className="truncate text-muted">{sousActivite.libelle}</span>
        <Pastille statut={sousActivite.statut} />
        <BarreExecution notifie={sousActivite.notifie} engage={sousActivite.engage} depense={sousActivite.depense} />
      </button>
    </li>
  )
}

/**
 * Progression déduite des étapes (§ 2.2) — en vert, pour ne jamais se
 * confondre avec le bleu de l'exécution budgétaire.
 *
 * `--color-success` et non `--color-ok` : ce dernier vaut exactement
 * `--color-accent` (#155E93) dans la maquette de référence, si bien que la
 * barre de progression et la barre budgétaire étaient rigoureusement de la
 * même couleur — écart ΔE nul, mesuré — alors que la légende les présentait
 * comme distinctes.
 */
function MiniBarreAvancement({ avancement, libelle }: { avancement: number | null; libelle: string }) {
  if (avancement === null) {
    return <span className="chiffres text-xs text-muted">—</span>
  }

  return (
    <span className="flex items-center gap-2" title={`${libelle} : ${avancement}%`}>
      <span className="h-1.5 w-16 shrink-0 overflow-hidden rounded-[var(--radius-barre)] bg-track">
        <span className="block h-full bg-success" style={{ width: `${avancement}%` }} />
      </span>
      <span className="chiffres shrink-0 text-xs text-muted">{avancement}%</span>
    </span>
  )
}

/** Uuid de tous les nœuds dépliables : composantes, sous-composantes, activités. */
function tousLesNoeuds(composantes: NoeudComposante[]): Set<string> {
  const tous = new Set<string>()
  for (const c of composantes) {
    tous.add(c.uuid)
    for (const sc of c.sousComposantes ?? []) {
      tous.add(sc.uuid)
      for (const a of sc.activites ?? []) {
        tous.add(a.uuid)
      }
    }
  }

  return tous
}
