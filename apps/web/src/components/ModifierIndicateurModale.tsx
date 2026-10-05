import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { modifierIndicateur, type Indicateur } from '@/lib/indicateurs'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  libelle: z.string().min(1, 'Le libellé est requis.'),
  precisionLibelle: z.string().optional(),
  unite: z.string().min(1, "L'unité est requise."),
  valeurIntermediaire: z.string().optional(),
  dateIntermediaire: z.string().optional(),
  valeurCible: z.string().min(1, 'La valeur cible est requise.'),
  dateCible: z.string().min(1, 'La date cible est requise.'),
  source: z.string().min(1, 'La source est requise.'),
  actif: z.boolean(),
})

type FormValues = z.infer<typeof schema>

interface ModifierIndicateurModaleProps {
  indicateur: Indicateur
  onFermer: () => void
}

export function ModifierIndicateurModale({ indicateur, onFermer }: ModifierIndicateurModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      libelle: indicateur.libelle,
      precisionLibelle: indicateur.precisionLibelle ?? '',
      unite: indicateur.unite,
      valeurIntermediaire: indicateur.valeurIntermediaire ?? '',
      dateIntermediaire: indicateur.dateIntermediaire?.slice(0, 10) ?? '',
      valeurCible: indicateur.valeurCible,
      dateCible: indicateur.dateCible.slice(0, 10),
      source: indicateur.source,
      actif: indicateur.actif,
    },
  })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await modifierIndicateur(indicateur.uuid, {
        ...valeurs,
        valeurIntermediaire: valeurs.valeurIntermediaire?.trim() === '' ? null : valeurs.valeurIntermediaire,
        dateIntermediaire: valeurs.dateIntermediaire?.trim() === '' ? null : valeurs.dateIntermediaire,
      })
      await queryClient.invalidateQueries({ queryKey: ['indicateurs'] })
      await queryClient.invalidateQueries({ queryKey: ['indicateur', indicateur.uuid] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre={`Modifier ${indicateur.code}`} onFermer={onFermer}>
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
          <label htmlFor="precisionLibelle">Précision (optionnel)</label>
          <input
            id="precisionLibelle"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('precisionLibelle')}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="unite">Unité</label>
            <input id="unite" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('unite')} />
            {errors.unite && <p className="text-bad text-sm">{errors.unite.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="source">Source</label>
            <input id="source" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('source')} />
            {errors.source && <p className="text-bad text-sm">{errors.source.message}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="valeurIntermediaire">Objectif intermédiaire (optionnel)</label>
            <input
              id="valeurIntermediaire"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('valeurIntermediaire')}
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="dateIntermediaire">Date intermédiaire (optionnel)</label>
            <input
              id="dateIntermediaire"
              type="date"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('dateIntermediaire')}
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="valeurCible">Valeur cible</label>
            <input
              id="valeurCible"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('valeurCible')}
            />
            {errors.valeurCible && <p className="text-bad text-sm">{errors.valeurCible.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="dateCible">Date cible</label>
            <input
              id="dateCible"
              type="date"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('dateCible')}
            />
            {errors.dateCible && <p className="text-bad text-sm">{errors.dateCible.message}</p>}
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" {...register('actif')} />
          Indicateur actif
        </label>

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
            {isSubmitting ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
