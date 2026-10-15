import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { creerSousActivite } from '@/lib/composantes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  libelle: z.string().min(1, 'Le libellé est requis.'),
  dateDebut: z.string().optional(),
  echeance: z.string().optional(),
})

type FormValues = z.infer<typeof schema>

interface CreerSousActiviteModaleProps {
  activiteUuid: string
  ordreSuivant: number
  onFermer: () => void
}

/** Modale de création — le responsable de structure hérite de celui de l'activité parente si non précisé (§ getStructurePourPortee). */
export function CreerSousActiviteModale({ activiteUuid, ordreSuivant, onFermer }: CreerSousActiviteModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await creerSousActivite({
        activite: activiteUuid,
        libelle: valeurs.libelle,
        ordre: ordreSuivant,
        dateDebut: valeurs.dateDebut === '' ? undefined : valeurs.dateDebut,
        echeance: valeurs.echeance === '' ? undefined : valeurs.echeance,
      })
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
      await queryClient.invalidateQueries({ queryKey: ['arbre'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Ajouter une ligne d'activité" onFermer={onFermer}>
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

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="dateDebut">Date de début</label>
            <input
              id="dateDebut"
              type="date"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('dateDebut')}
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="echeance">Échéance</label>
            <input
              id="echeance"
              type="date"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('echeance')}
            />
          </div>
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
            {isSubmitting ? 'Création…' : 'Créer'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
