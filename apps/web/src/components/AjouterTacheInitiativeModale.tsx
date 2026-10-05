import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { creerTacheInitiative, responsablesEligiblesQueryOptions } from '@/lib/initiatives'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  libelle: z.string().min(1, 'Le libellé est requis.'),
  responsable: z.string().optional(),
})

type FormValues = z.infer<typeof schema>

interface AjouterTacheInitiativeModaleProps {
  initiativeActiviteUuid: string
  onFermer: () => void
}

export function AjouterTacheInitiativeModale({ initiativeActiviteUuid, onFermer }: AjouterTacheInitiativeModaleProps) {
  const { data: utilisateurs } = useQuery(responsablesEligiblesQueryOptions())
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await creerTacheInitiative({
        initiativeActivite: initiativeActiviteUuid,
        libelle: valeurs.libelle,
        responsable: valeurs.responsable === '' ? undefined : valeurs.responsable,
      })
      await queryClient.invalidateQueries({ queryKey: ['initiatives'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Ajouter une tâche" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="libelle">Libellé</label>
          <input id="libelle" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('libelle')} />
          {errors.libelle && <p className="text-bad text-sm">{errors.libelle.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="responsable">Responsable</label>
          <select id="responsable" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('responsable')}>
            <option value="">Aucun</option>
            {(utilisateurs?.donnees ?? []).map((u) => (
              <option key={u.uuid} value={u.uuid}>
                {u.nomComplet}
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
            type="submit"
            disabled={isSubmitting}
            className="rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d disabled:opacity-60"
          >
            {isSubmitting ? 'Ajout…' : 'Ajouter'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
