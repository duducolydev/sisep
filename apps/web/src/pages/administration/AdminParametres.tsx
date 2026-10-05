import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { parametresQueryOptions, modifierParametres } from '@/lib/admin'
import { queryClient } from '@/lib/query-client'
import { ApiError } from '@/lib/api-client'
import { AdminOnglets } from '@/components/AdminOnglets'
import { Squelette } from '@/components/Squelette'
import { formaterDate } from '@/lib/format'

export function AdminParametres() {
  const { data: parametres, isLoading, isError } = useQuery(parametresQueryOptions())
  const [valeurs, setValeurs] = useState<Record<string, string>>({})
  const [enregistrement, setEnregistrement] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)
  const [confirmation, setConfirmation] = useState(false)

  useEffect(() => {
    if (parametres) {
      setValeurs(Object.fromEntries(parametres.donnees.map((p) => [p.code, p.valeur])))
    }
  }, [parametres])

  async function enregistrer() {
    setErreur(null)
    setConfirmation(false)
    setEnregistrement(true)
    try {
      await modifierParametres(valeurs)
      await queryClient.invalidateQueries({ queryKey: parametresQueryOptions().queryKey })
      setConfirmation(true)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnregistrement(false)
    }
  }

  return (
    <div>
      <p className="font-titre text-eyebrow uppercase tracking-wide">Backoffice</p>
      <h1 className="text-2xl">Administration</h1>
      <p className="mb-6 text-muted">Référentiels, parties prenantes, rôles et permissions, journal des actions.</p>

      <AdminOnglets />

      <h2 className="mb-2 text-lg font-semibold">Paramètres</h2>
      <p className="mb-4 text-sm text-muted">
        Seuils de vigilance (§ 5.3) et échéance de reporting (§ 5.5). Effectifs immédiatement, sans redéploiement.
      </p>

      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}
      {confirmation && (
        <div role="status" className="mb-4 rounded-[var(--radius-controle)] bg-ok-bg px-4 py-3 text-ok">
          Paramètres enregistrés.
        </div>
      )}

      {isLoading && <Squelette lignes={3} />}
      {isError && <p className="text-bad">Impossible de charger les paramètres. Réessayez.</p>}

      {parametres && (
        <div className="flex max-w-xl flex-col gap-4 rounded-[var(--radius-carte)] bg-surface p-5 shadow-[var(--shadow-carte)]">
          {parametres.donnees.map((p) => (
            <div key={p.code} className="flex flex-col gap-1">
              <label htmlFor={p.code} className="text-sm font-medium">
                {p.description ?? p.code}
              </label>
              <div className="flex items-center gap-3">
                <input
                  id={p.code}
                  className="chiffres w-40 rounded-[var(--radius-controle)] border border-border px-3 py-2"
                  value={valeurs[p.code] ?? ''}
                  onChange={(e) => setValeurs((precedent) => ({ ...precedent, [p.code]: e.target.value }))}
                />
                <span className="chiffres text-xs text-muted">{p.code}</span>
              </div>
              {p.modifieLe && <p className="text-xs text-muted">Dernière modification : {formaterDate(p.modifieLe)}</p>}
            </div>
          ))}

          <div className="mt-2 flex justify-end">
            <button
              type="button"
              onClick={enregistrer}
              disabled={enregistrement}
              className="rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d disabled:opacity-60"
            >
              {enregistrement ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
