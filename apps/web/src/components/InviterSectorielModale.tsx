import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { ComboboxSimple } from './ComboboxSimple'
import { sectorielsEligiblesQueryOptions, inviterSectoriel } from '@/lib/indicateurs'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface InviterSectorielModaleProps {
  indicateurUuid: string
  onFermer: () => void
}

/** Ouvre l'accès (consultation + saisie) à un sectoriel sur ce seul indicateur, sans lui redonner les permissions globales (§ décision). */
export function InviterSectorielModale({ indicateurUuid, onFermer }: InviterSectorielModaleProps) {
  const { data: eligibles } = useQuery(sectorielsEligiblesQueryOptions())
  const [utilisateurUuid, setUtilisateurUuid] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  async function envoyer() {
    if (utilisateurUuid === '') {
      setErreur('Choisissez un sectoriel.')

      return
    }
    setErreur(null)
    setEnCours(true)
    try {
      await inviterSectoriel(indicateurUuid, utilisateurUuid)
      await queryClient.invalidateQueries({ queryKey: ['indicateur', indicateurUuid, 'invitations'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre="Inviter un sectoriel" onFermer={onFermer}>
      <p className="mb-4 text-sm text-muted">
        Ouvre l'accès (consultation et saisie) à cet indicateur pour la personne choisie, sans lui accorder les permissions
        globales du module.
      </p>

      <div className="flex flex-col gap-1 text-sm">
        <label htmlFor="sectoriel-invite">Sectoriel</label>
        <ComboboxSimple
          id="sectoriel-invite"
          options={(eligibles?.donnees ?? []).map((u) => ({ value: u.uuid, label: u.nomComplet }))}
          valeur={utilisateurUuid}
          onChange={setUtilisateurUuid}
          placeholder="Choisir…"
          disabled={enCours}
        />
      </div>

      {erreur && (
        <p role="alert" className="mt-3 text-sm text-bad">
          {erreur}
        </p>
      )}

      <div className="mt-5 flex justify-end gap-3 border-t border-border pt-4">
        <button
          type="button"
          onClick={onFermer}
          className="rounded-[var(--radius-controle)] border border-border bg-surface px-4 py-2 font-semibold text-text transition-colors hover:border-accent hover:text-accent"
        >
          Annuler
        </button>
        <button
          type="button"
          onClick={() => void envoyer()}
          disabled={enCours}
          className="rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d disabled:opacity-60"
        >
          {enCours ? 'Envoi…' : 'Inviter'}
        </button>
      </div>
    </Modale>
  )
}
