import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { creerIndicateur } from '@/lib/indicateurs'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import type { LigneExecution } from '@/lib/budget'

const schema = z.object({
  code: z.string().min(1, 'Le code est requis.').max(20),
  libelle: z.string().min(1, 'Le libellé est requis.'),
  precisionLibelle: z.string().optional(),
  type: z.enum(['IOD', 'IRI']),
  composante: z.string().optional(),
  unite: z.string().min(1, "L'unité est requise."),
  sens: z.enum(['croissant', 'decroissant']),
  valeurReference: z.string().min(1, 'La valeur de référence est requise.'),
  dateReference: z.string().min(1, 'La date de référence est requise.'),
  valeurIntermediaire: z.string().optional(),
  dateIntermediaire: z.string().optional(),
  valeurCible: z.string().min(1, 'La valeur cible est requise.'),
  dateCible: z.string().min(1, 'La date cible est requise.'),
  frequence: z.enum(['mensuelle', 'trimestrielle', 'semestrielle', 'annuelle']),
  source: z.string().min(1, 'La source est requise.'),
})

type FormValues = z.infer<typeof schema>

interface DefinirIndicateurModaleProps {
  composantes: LigneExecution[]
  onFermer: () => void
}

export function DefinirIndicateurModale({ composantes, onFermer }: DefinirIndicateurModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await creerIndicateur({
        ...valeurs,
        valeurIntermediaire: valeurs.valeurIntermediaire?.trim() === '' ? undefined : valeurs.valeurIntermediaire,
        dateIntermediaire: valeurs.dateIntermediaire?.trim() === '' ? undefined : valeurs.dateIntermediaire,
      })
      await queryClient.invalidateQueries({ queryKey: ['indicateurs'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Définir un indicateur" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto">
        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="code">Code</label>
            <input id="code" className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('code')} />
            {errors.code && <p className="text-bad text-sm">{errors.code.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="type">Type</label>
            <select id="type" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('type')}>
              <option value="IOD">IOD — objectif de développement</option>
              <option value="IRI">IRI — résultat intermédiaire</option>
            </select>
          </div>
        </div>

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

        <div className="flex flex-col gap-1">
          <label htmlFor="composante">Rattachement</label>
          <select id="composante" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('composante')}>
            <option value="">Objectif général (indicateur transversal)</option>
            {composantes.map((c) => (
              <option key={c.uuid} value={c.uuid}>
                {c.code} — {c.libelle}
              </option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="unite">Unité</label>
            <input id="unite" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('unite')} />
            {errors.unite && <p className="text-bad text-sm">{errors.unite.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="sens">Sens</label>
            <select id="sens" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('sens')}>
              <option value="croissant">Croissant</option>
              <option value="decroissant">Décroissant</option>
            </select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="valeurReference">Valeur de référence</label>
            <input
              id="valeurReference"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('valeurReference')}
            />
            {errors.valeurReference && <p className="text-bad text-sm">{errors.valeurReference.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="dateReference">Date de référence</label>
            <input
              id="dateReference"
              type="date"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('dateReference')}
            />
            {errors.dateReference && <p className="text-bad text-sm">{errors.dateReference.message}</p>}
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

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="frequence">Fréquence</label>
            <select id="frequence" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('frequence')}>
              <option value="mensuelle">Mensuelle</option>
              <option value="trimestrielle">Trimestrielle</option>
              <option value="semestrielle">Semestrielle</option>
              <option value="annuelle">Annuelle</option>
            </select>
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="source">Source</label>
            <input id="source" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('source')} />
            {errors.source && <p className="text-bad text-sm">{errors.source.message}</p>}
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
            {isSubmitting ? 'Enregistrement…' : 'Définir'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
