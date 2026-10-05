import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { creerDepense } from '@/lib/budget'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  montant: z.coerce.number().int().positive('Le montant doit être positif.'),
  dateDepense: z.string().min(1, 'La date de dépense est requise.'),
  referencePaiement: z.string().optional(),
})

type FormEntree = z.input<typeof schema>
type FormValues = z.output<typeof schema>

interface SaisirDepenseModaleProps {
  engagementUuid: string
  activiteUuid: string
  onFermer: () => void
}

export function SaisirDepenseModale({ engagementUuid, activiteUuid, onFermer }: SaisirDepenseModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormEntree, unknown, FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await creerDepense({ engagement: engagementUuid, ...valeurs })
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
      await queryClient.invalidateQueries({ queryKey: ['budget'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Saisir une dépense" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="montant">Montant (FCFA)</label>
          <input
            id="montant"
            type="number"
            className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('montant')}
          />
          {errors.montant && <p className="text-bad text-sm">{errors.montant.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="dateDepense">Date de dépense</label>
          <input
            id="dateDepense"
            type="date"
            className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('dateDepense')}
          />
          {errors.dateDepense && <p className="text-bad text-sm">{errors.dateDepense.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="referencePaiement">Référence de paiement (optionnel)</label>
          <input
            id="referencePaiement"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('referencePaiement')}
          />
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
            {isSubmitting ? 'Enregistrement…' : 'Saisir'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
