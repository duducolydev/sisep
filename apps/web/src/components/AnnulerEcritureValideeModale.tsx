import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { annulerDepenseValidee, annulerEngagementValide } from '@/lib/budget'
import { ApiError } from '@/lib/api-client'
import { formaterFcfa } from '@/lib/format'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  motif: z.string().trim().min(1, "Le motif de l'annulation est requis.").max(1000, 'Le motif tient en 1000 caractères au plus.'),
})

type FormEntree = z.input<typeof schema>
type FormValues = z.output<typeof schema>

export interface EcritureAAnnuler {
  type: 'engagement' | 'depense'
  uuid: string
  montant: number
  reference: string | null
}

interface AnnulerEcritureValideeModaleProps {
  ecriture: EcritureAAnnuler
  activiteUuid: string
  onFermer: () => void
}

/** Un montant validé ne se réécrit pas : l'écriture s'annule avec un motif, puis se ressaisit. */
export function AnnulerEcritureValideeModale({ ecriture, activiteUuid, onFermer }: AnnulerEcritureValideeModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)
  const estEngagement = ecriture.type === 'engagement'

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormEntree, unknown, FormValues>({ resolver: zodResolver(schema), defaultValues: { motif: '' } })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await (estEngagement ? annulerEngagementValide(ecriture.uuid, valeurs.motif) : annulerDepenseValidee(ecriture.uuid, valeurs.motif))
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
      await queryClient.invalidateQueries({ queryKey: ['budget'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre={estEngagement ? 'Annuler cet engagement validé' : 'Annuler cette dépense validée'} onFermer={onFermer}>
      <p className="mb-4 text-sm text-muted">
        {ecriture.reference ?? 'Sans référence'} · <span className="chiffres">{formaterFcfa(ecriture.montant)}</span>. Cette annulation ne peut pas être
        défaite : le montant sort des totaux, l'écriture reste visible avec son motif et le journal en garde la trace. Pour corriger, saisissez ensuite la
        bonne écriture.
      </p>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="motif">Motif de l'annulation</label>
          <textarea id="motif" rows={3} maxLength={1000} className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('motif')} />
          {errors.motif && <p className="text-bad text-sm">{errors.motif.message}</p>}
        </div>

        <div className="mt-4 flex justify-end gap-3 border-t border-border pt-4">
          <button
            type="button"
            onClick={onFermer}
            className="rounded-[var(--radius-controle)] border border-border bg-surface px-4 py-2 font-semibold text-text transition-colors hover:border-accent hover:text-accent"
          >
            Conserver
          </button>
          <button
            type="submit"
            disabled={isSubmitting}
            className="rounded-[var(--radius-controle)] bg-bad px-4 py-2 font-semibold text-white transition-colors disabled:opacity-60"
          >
            {isSubmitting ? 'Annulation…' : "Annuler l'écriture"}
          </button>
        </div>
      </form>
    </Modale>
  )
}
