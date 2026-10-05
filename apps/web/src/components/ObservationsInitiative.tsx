import { useState } from 'react'
import { ajouterNoteInitiative, type EntiteInitiative, type NoteInitiative } from '@/lib/initiatives'
import { formaterDate } from '@/lib/format'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { IconeBouton, IconePlus } from './Icones'
import { Bouton } from './Bouton'

interface ObservationsInitiativeProps {
  entiteType: EntiteInitiative
  entiteUuid: string
  notes: NoteInitiative[]
  peutAjouter: boolean
}

/** Observations libres sur une activité ou une tâche de projet — motif calqué sur l'ancien ListeNotes des diligences. */
export function ObservationsInitiative({ entiteType, entiteUuid, notes, peutAjouter }: ObservationsInitiativeProps) {
  const [formulaireOuvert, setFormulaireOuvert] = useState(false)
  const [contenu, setContenu] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  async function ajouter() {
    if (contenu.trim() === '') return
    setErreur(null)
    setEnCours(true)
    try {
      await ajouterNoteInitiative(entiteType, entiteUuid, contenu.trim())
      await queryClient.invalidateQueries({ queryKey: ['initiatives'] })
      setContenu('')
      setFormulaireOuvert(false)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div>
      <div className="mb-1 flex items-center justify-between">
        <p className="text-xs font-semibold uppercase tracking-wide text-muted">Observations</p>
        {peutAjouter && !formulaireOuvert && (
          <IconeBouton libelle="Ajouter une observation" onClick={() => setFormulaireOuvert(true)}>
            <IconePlus />
          </IconeBouton>
        )}
      </div>

      {erreur && <p className="mb-2 text-sm text-bad">{erreur}</p>}

      {notes.length === 0 ? (
        <p className="text-sm text-muted">Aucune observation pour le moment.</p>
      ) : (
        <ul className="flex flex-col gap-2 text-sm">
          {notes.map((n) => (
            <li key={n.uuid} className="border-b border-border pb-2 last:border-0">
              <p>{n.contenu}</p>
              <p className="text-xs text-muted">
                {formaterDate(n.creeLe)}
                {n.creePar && ` — ${n.creePar}`}
              </p>
            </li>
          ))}
        </ul>
      )}

      {formulaireOuvert && (
        <div className="mt-3 flex flex-col gap-2">
          <textarea
            rows={2}
            autoFocus
            value={contenu}
            onChange={(e) => setContenu(e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2 text-sm"
          />
          <div className="flex justify-end gap-2">
            <Bouton variante="secondaire" onClick={() => setFormulaireOuvert(false)}>
              Annuler
            </Bouton>
            <button
              type="button"
              onClick={ajouter}
              disabled={enCours || contenu.trim() === ''}
              className="rounded-[var(--radius-controle)] bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-accent-d disabled:opacity-60"
            >
              {enCours ? 'Ajout…' : 'Ajouter'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
