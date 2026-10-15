import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { creerEngagement, NATURES_ENGAGEMENT as NATURES } from '@/lib/budget'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  exercice: z.coerce.number().int().min(2000).max(2100),
  nature: z.string().min(1, 'La nature est requise.'),
  montant: z.coerce.number().int().positive('Le montant doit être positif.'),
  dateEngagement: z.string().min(1, "La date d'engagement est requise."),
  referenceMarche: z.string().optional(),
  observations: z.string().optional(),
})

type FormEntree = z.input<typeof schema>
type FormValues = z.output<typeof schema>

interface SaisirEngagementModaleProps {
  cible: { type: 'activite' | 'sous_activite'; uuid: string }
  activiteUuid: string
  exerciceParDefaut: number
  onFermer: () => void
}

export function SaisirEngagementModale({ cible, activiteUuid, exerciceParDefaut, onFermer }: SaisirEngagementModaleProps) {
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
      await creerEngagement({ [cible.type === 'activite' ? 'activite' : 'sousActivite']: cible.uuid, ...valeurs })
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
      await queryClient.invalidateQueries({ queryKey: ['budget'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Saisir un engagement" onFermer={onFermer}>
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
          <label htmlFor="nature">Nature</label>
          <select id="nature" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('nature')}>
            <option value="">— Choisir —</option>
            {NATURES.filter((n) => !n.groupe).map((n) => (
              <option key={n.valeur} value={n.valeur}>
                {n.libelle}
              </option>
            ))}
            <optgroup label="Fonctionnement">
              {NATURES.filter((n) => n.groupe === 'Fonctionnement').map((n) => (
                <option key={n.valeur} value={n.valeur}>
                  {n.libelle}
                </option>
              ))}
            </optgroup>
          </select>
          {errors.nature && <p className="text-bad text-sm">{errors.nature.message}</p>}
        </div>

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
          <label htmlFor="dateEngagement">Date d'engagement</label>
          <input
            id="dateEngagement"
            type="date"
            className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('dateEngagement')}
          />
          {errors.dateEngagement && <p className="text-bad text-sm">{errors.dateEngagement.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="referenceMarche">Référence marché (optionnel)</label>
          <input
            id="referenceMarche"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('referenceMarche')}
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
