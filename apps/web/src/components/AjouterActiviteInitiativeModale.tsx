import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { ComboboxMultiple } from './ComboboxMultiple'
import { creerInitiativeActivite, responsablesEligiblesQueryOptions } from '@/lib/initiatives'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  libelle: z.string().min(1, 'Le libellé est requis.'),
  dateDebut: z.string().optional(),
  echeance: z.string().optional(),
})

type FormValues = z.infer<typeof schema>

interface AjouterActiviteInitiativeModaleProps {
  initiativeUuid: string
  onFermer: () => void
}

export function AjouterActiviteInitiativeModale({ initiativeUuid, onFermer }: AjouterActiviteInitiativeModaleProps) {
  const { data: utilisateurs } = useQuery(responsablesEligiblesQueryOptions())
  const [responsables, setResponsables] = useState<string[]>([])
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await creerInitiativeActivite({
        initiative: initiativeUuid,
        libelle: valeurs.libelle,
        dateDebut: valeurs.dateDebut === '' ? undefined : valeurs.dateDebut,
        echeance: valeurs.echeance === '' ? undefined : valeurs.echeance,
        responsables,
      })
      await queryClient.invalidateQueries({ queryKey: ['initiatives'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Ajouter une activité" onFermer={onFermer}>
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

        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 text-sm font-medium">Responsable(s)</legend>
          <ComboboxMultiple
            options={(utilisateurs?.donnees ?? []).map((u) => ({ value: u.uuid, label: u.nomComplet }))}
            selectionnes={responsables}
            onChange={setResponsables}
            placeholder="Choisir des responsables…"
          />
        </fieldset>

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
