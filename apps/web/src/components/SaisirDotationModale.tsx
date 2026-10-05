import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { creerDotation } from '@/lib/budget'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  exercice: z.coerce.number().int().min(2000).max(2100),
  montant: z.coerce.number().int().positive('Le montant doit être positif.'),
  q1: z.coerce.number().int().nonnegative().optional().or(z.literal('')),
  q2: z.coerce.number().int().nonnegative().optional().or(z.literal('')),
  q3: z.coerce.number().int().nonnegative().optional().or(z.literal('')),
  q4: z.coerce.number().int().nonnegative().optional().or(z.literal('')),
  montantValideCopil: z.coerce.number().int().nonnegative().optional().or(z.literal('')),
  commentaireRevision: z.string().optional(),
})

type FormEntree = z.input<typeof schema>
type FormValues = z.output<typeof schema>

interface SaisirDotationModaleProps {
  cible: { type: 'activite' | 'sous_activite'; uuid: string }
  activiteUuid: string
  exerciceParDefaut: number
  onFermer: () => void
}

export function SaisirDotationModale({ cible, activiteUuid, exerciceParDefaut, onFermer }: SaisirDotationModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormEntree, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { exercice: exerciceParDefaut },
  })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await creerDotation({
        [cible.type === 'activite' ? 'activite' : 'sousActivite']: cible.uuid,
        exercice: valeurs.exercice,
        montant: valeurs.montant,
        q1: valeurs.q1 === '' ? undefined : valeurs.q1,
        q2: valeurs.q2 === '' ? undefined : valeurs.q2,
        q3: valeurs.q3 === '' ? undefined : valeurs.q3,
        q4: valeurs.q4 === '' ? undefined : valeurs.q4,
        montantValideCopil: valeurs.montantValideCopil === '' ? undefined : valeurs.montantValideCopil,
        commentaireRevision: valeurs.commentaireRevision?.trim() === '' ? undefined : valeurs.commentaireRevision,
      })
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
      await queryClient.invalidateQueries({ queryKey: ['budget'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Notifier une dotation" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="exercice">Exercice</label>
          <input
            id="exercice"
            type="number"
            className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('exercice')}
          />
          {errors.exercice && <p className="text-bad text-sm">{errors.exercice.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="montant">Montant notifié (FCFA)</label>
          <input
            id="montant"
            type="number"
            className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('montant')}
          />
          {errors.montant && <p className="text-bad text-sm">{errors.montant.message}</p>}
        </div>

        <details className="rounded-[var(--radius-controle)] border border-border px-3 py-2">
          <summary className="cursor-pointer text-sm text-muted">Répartition trimestrielle (optionnel)</summary>
          <div className="mt-3 grid grid-cols-4 gap-3">
            {(['q1', 'q2', 'q3', 'q4'] as const).map((champ, i) => (
              <div key={champ} className="flex flex-col gap-1">
                <label htmlFor={champ}>TRIM {i + 1}</label>
                <input
                  id={champ}
                  type="number"
                  className="chiffres rounded-[var(--radius-controle)] border border-border px-2 py-1.5 text-right"
                  {...register(champ)}
                />
                {errors[champ] && <p className="text-bad text-xs">{errors[champ]?.message}</p>}
              </div>
            ))}
          </div>
        </details>

        <details className="rounded-[var(--radius-controle)] border border-border px-3 py-2">
          <summary className="cursor-pointer text-sm text-muted">Révision COPIL (optionnel)</summary>
          <div className="mt-3 flex flex-col gap-4">
            <div className="flex flex-col gap-1">
              <label htmlFor="montantValideCopil">Montant validé par le COPIL (FCFA)</label>
              <input
                id="montantValideCopil"
                type="number"
                className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
                {...register('montantValideCopil')}
              />
              {errors.montantValideCopil && <p className="text-bad text-sm">{errors.montantValideCopil.message}</p>}
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="commentaireRevision">Commentaire sur les changements</label>
              <textarea
                id="commentaireRevision"
                rows={2}
                className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
                {...register('commentaireRevision')}
              />
            </div>
          </div>
        </details>

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
            {isSubmitting ? 'Enregistrement…' : 'Notifier'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
