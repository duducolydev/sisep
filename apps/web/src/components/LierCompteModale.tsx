import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { lierCompteUtilisateur, type PointFocal } from '@/lib/parties-prenantes'
import { utilisateursQueryOptions } from '@/lib/utilisateurs'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface LierCompteModaleProps {
  pointFocal: PointFocal
  onFermer: () => void
}

/** Rattache un compte de connexion existant à une fiche point focal — la création du compte se fait ailleurs (§ Administration › Comptes utilisateurs). */
export function LierCompteModale({ pointFocal, onFermer }: LierCompteModaleProps) {
  const { data: utilisateurs } = useQuery(utilisateursQueryOptions())
  const [utilisateurUuid, setUtilisateurUuid] = useState('')
  const [erreur, setErreur] = useState<string | null>(null)
  const [enCours, setEnCours] = useState(false)

  async function lier() {
    if (utilisateurUuid === '') return
    setErreur(null)
    setEnCours(true)
    try {
      await lierCompteUtilisateur(pointFocal.uuid, utilisateurUuid)
      await queryClient.invalidateQueries({ queryKey: ['points-focaux'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre={`Lier un compte à ${pointFocal.nomComplet}`} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <div className="flex flex-col gap-1">
        <label htmlFor="utilisateur">Compte utilisateur</label>
        <select
          id="utilisateur"
          value={utilisateurUuid}
          onChange={(e) => setUtilisateurUuid(e.target.value)}
          className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
        >
          <option value="">Sélectionner un compte…</option>
          {utilisateurs?.donnees.map((u) => (
            <option key={u.uuid} value={u.uuid}>
              {u.prenom} {u.nom} — {u.email}
            </option>
          ))}
        </select>
      </div>

      <div className="mt-4 flex justify-end gap-3 border-t border-border pt-4">
        <button
          type="button"
          onClick={onFermer}
          className="rounded-[var(--radius-controle)] border border-border bg-surface px-4 py-2 font-semibold text-text transition-colors hover:border-accent hover:text-accent"
        >
          Annuler
        </button>
        <button
          type="button"
          onClick={lier}
          disabled={utilisateurUuid === '' || enCours}
          className="rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d disabled:opacity-60"
        >
          {enCours ? 'Liaison…' : 'Lier ce compte'}
        </button>
      </div>
    </Modale>
  )
}
