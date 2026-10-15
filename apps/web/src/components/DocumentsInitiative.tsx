import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  televerserPieceJointeInitiative,
  responsablesEligiblesQueryOptions,
  type EntiteInitiative,
  type PieceJointeInitiative,
  type VisibilitePieceJointe,
} from '@/lib/initiatives'
import { urlTelechargerPieceJointe } from '@/lib/exports'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { IconeTrombone } from './Icones'
import { DropzoneFichier } from './DropzoneFichier'
import { ComboboxMultiple } from './ComboboxMultiple'
import { ChoixTypeDocument } from './ChoixTypeDocument'
import { erreurNature, NATURE_VIDE, type NatureDocument } from '@/lib/types-document'

interface DocumentsInitiativeProps {
  entiteType: EntiteInitiative
  entiteUuid: string
  piecesJointes: PieceJointeInitiative[]
  peutAjouter: boolean
}

const LIBELLES_VISIBILITE: Record<VisibilitePieceJointe, string> = {
  tous: 'Tous',
  equipe_phasaoc: 'Équipe PHASAOC',
  sectoriels: 'Sectoriels',
  agents_specifiques: 'Agent(s) spécifique(s)',
}

function formaterTaille(octets: number): string {
  if (octets < 1024) return `${octets} o`
  if (octets < 1024 * 1024) return `${Math.round(octets / 1024)} Ko`
  return `${(octets / (1024 * 1024)).toFixed(1)} Mo`
}

/** Pièces jointes sur une activité ou une tâche de projet — visibilité contrôlée à l'ajout, téléchargement arbitré côté serveur selon celle-ci. */
export function DocumentsInitiative({ entiteType, entiteUuid, piecesJointes, peutAjouter }: DocumentsInitiativeProps) {
  const { data: utilisateurs } = useQuery(responsablesEligiblesQueryOptions())
  const [panneauOuvert, setPanneauOuvert] = useState(false)
  const [fichier, setFichier] = useState<File | null>(null)
  const [visibilite, setVisibilite] = useState<VisibilitePieceJointe>('tous')
  const [agents, setAgents] = useState<string[]>([])
  const [nature, setNature] = useState<NatureDocument>(NATURE_VIDE)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  function reinitialiser() {
    setPanneauOuvert(false)
    setFichier(null)
    setVisibilite('tous')
    setAgents([])
    setNature(NATURE_VIDE)
    setErreur(null)
  }

  async function televerser() {
    if (!fichier) return
    const erreurType = erreurNature(nature)
    if (erreurType !== null) {
      setErreur(erreurType)

      return
    }
    setErreur(null)
    setEnCours(true)
    try {
      await televerserPieceJointeInitiative(entiteType, entiteUuid, fichier, visibilite, agents, nature)
      await queryClient.invalidateQueries({ queryKey: ['initiatives'] })
      reinitialiser()
    } catch (e) {
      setErreur(e instanceof ApiError || e instanceof Error ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div>
      <div className="mb-1 flex items-center justify-between">
        <p className="text-xs font-semibold uppercase tracking-wide text-muted">Documents</p>
        {peutAjouter && !panneauOuvert && (
          <button
            type="button"
            onClick={() => setPanneauOuvert(true)}
            className="flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-accent-d"
            title="Ajouter un document"
          >
            <IconeTrombone />
          </button>
        )}
      </div>

      {panneauOuvert && (
        <div className="mb-3 flex flex-col gap-2 rounded-[var(--radius-controle)] border border-border bg-nested p-3">
          <DropzoneFichier fichier={fichier} onChange={setFichier} />

          <ChoixTypeDocument id={`type-document-${entiteUuid}`} nature={nature} onChange={setNature} disabled={enCours} />

          <label className="flex flex-col gap-1 text-sm">
            Visible par
            <select
              value={visibilite}
              onChange={(e) => setVisibilite(e.target.value as VisibilitePieceJointe)}
              className="rounded-[var(--radius-controle)] border border-border px-2 py-1.5"
            >
              {(Object.entries(LIBELLES_VISIBILITE) as [VisibilitePieceJointe, string][]).map(([valeur, libelle]) => (
                <option key={valeur} value={valeur}>
                  {libelle}
                </option>
              ))}
            </select>
          </label>

          {visibilite === 'agents_specifiques' && (
            <ComboboxMultiple
              options={(utilisateurs?.donnees ?? []).map((u) => ({ value: u.uuid, label: u.nomComplet }))}
              selectionnes={agents}
              onChange={setAgents}
              placeholder="Choisir des agents…"
            />
          )}

          {erreur && <p className="text-sm text-bad">{erreur}</p>}

          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={reinitialiser}
              className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-1.5 text-sm font-semibold text-text transition-colors hover:border-accent hover:text-accent"
            >
              Annuler
            </button>
            <button
              type="button"
              disabled={!fichier || enCours || (visibilite === 'agents_specifiques' && agents.length === 0)}
              onClick={() => void televerser()}
              className="rounded-[var(--radius-controle)] bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-accent-d disabled:opacity-60"
            >
              {enCours ? 'Envoi…' : 'Téléverser'}
            </button>
          </div>
        </div>
      )}

      {piecesJointes.length === 0 ? (
        <p className="text-sm text-muted">Aucun document pour le moment.</p>
      ) : (
        <ul className="flex flex-col gap-1 text-sm">
          {piecesJointes.map((p) => (
            <li key={p.uuid} className="flex items-center gap-2">
              <span aria-hidden="true">📎</span>
              <a href={urlTelechargerPieceJointe(p.uuid)} className="min-w-0 truncate text-accent hover:text-accent-d">
                {p.nomFichier}
              </a>
              {p.typeDocumentLibelle && <span className="shrink-0 rounded-full bg-nested px-2 py-0.5 text-xs text-muted">{p.typeDocumentLibelle}</span>}
              <span className="chiffres shrink-0 text-xs text-muted">{formaterTaille(p.tailleOctets)}</span>
              {p.visibilite !== 'tous' && (
                <span className="shrink-0 rounded-full bg-nested px-2 py-0.5 text-xs text-muted">
                  {p.visibilite === 'agents_specifiques' ? `${p.agentsVisibilite.length} agent(s)` : LIBELLES_VISIBILITE[p.visibilite]}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
