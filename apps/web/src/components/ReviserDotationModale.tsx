import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { reviserDotation, type Dotation } from '@/lib/budget'
import { ApiError } from '@/lib/api-client'
import { formaterFcfa } from '@/lib/format'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  montant: z.coerce.number().int('Le montant est un nombre entier de FCFA.').nonnegative('Le montant ne peut pas être négatif.'),
  motif: z.string().trim().min(1, 'Le motif de la révision est requis.').max(1000, 'Le motif tient en 1000 caractères au plus.'),
  montantValideCopil: z.coerce.number().int().nonnegative().optional().or(z.literal('')),
})

type FormEntree = z.input<typeof schema>
type FormValues = z.output<typeof schema>

interface ReviserDotationModaleProps {
  dotation: Dotation
  activiteUuid: string
  onFermer: () => void
}

/** Révision du montant notifié : le motif est obligatoire, le serveur refuse de descendre sous le déjà engagé. */
export function ReviserDotationModale({ dotation, activiteUuid, onFermer }: ReviserDotationModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormEntree, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { montant: dotation.montant, motif: '', montantValideCopil: dotation.montantValideCopil ?? '' },
  })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await reviserDotation(dotation.uuid, {
        montant: valeurs.montant,
        motif: valeurs.motif,
        montantValideCopil: valeurs.montantValideCopil === '' ? undefined : valeurs.montantValideCopil,
      })
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
      await queryClient.invalidateQueries({ queryKey: ['budget'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre={`Réviser la dotation ${dotation.exercice}`} onFermer={onFermer}>
      <p className="mb-4 text-sm text-muted">
        Montant notifié actuel : <span className="chiffres">{formaterFcfa(dotation.montant)}</span>. Il ne peut pas descendre sous le total déjà
        engagé et validé. L'ancien et le nouveau montant restent au journal.
      </p>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="montant">Nouveau montant notifié (FCFA)</label>
          <input id="montant" type="number" className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('montant')} />
          {errors.montant && <p className="text-bad text-sm">{errors.montant.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="motif">Motif de la révision</label>
          <textarea id="motif" rows={3} maxLength={1000} className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('motif')} />
          {errors.motif && <p className="text-bad text-sm">{errors.motif.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="montantValideCopil">Référence COPIL (FCFA, optionnel)</label>
          <input
            id="montantValideCopil"
            type="number"
            className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('montantValideCopil')}
          />
          <p className="text-xs text-muted">Pour information : le plafond des engagements reste le montant notifié.</p>
          {errors.montantValideCopil && <p className="text-bad text-sm">{errors.montantValideCopil.message}</p>}
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
            {isSubmitting ? 'Enregistrement…' : 'Réviser'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
