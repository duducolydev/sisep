import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { creerInitiative, modifierInitiative, type Initiative } from '@/lib/initiatives'
import type { NoeudComposante } from '@/lib/composantes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z
  .object({
    libelle: z.string().min(1, 'Le libellé est requis.').max(160, 'Le libellé ne doit pas dépasser 160 caractères.'),
    description: z.string().optional(),
    lien: z.enum(['independant', 'composante', 'sous_composante']),
    composante: z.string().optional(),
    sousComposante: z.string().optional(),
  })
  // Sans cela, « Lié à une composante » sans composante choisie enregistrait un projet indépendant.
  .refine((v) => v.lien !== 'composante' || !!v.composante, { path: ['composante'], message: 'Choisissez une composante.' })
  .refine((v) => v.lien !== 'sous_composante' || !!v.sousComposante, {
    path: ['sousComposante'],
    message: 'Choisissez une sous-composante.',
  })

type FormValues = z.infer<typeof schema>

interface InitiativeModaleProps {
  composantes: NoeudComposante[]
  /** Projet à modifier ; absent, la modale en crée un. */
  initiative?: Initiative
  onFermer: () => void
}

/** Un projet peut être indépendant du PTBA ou rattaché à UNE composante ou UNE sous-composante — jamais les deux (§ décision produit). */
export function InitiativeModale({ composantes, initiative, onFermer }: InitiativeModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)
  const lienActuel = initiative?.lienPtba ?? null

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      libelle: initiative?.libelle ?? '',
      description: initiative?.description ?? '',
      lien: lienActuel?.type ?? 'independant',
      composante: lienActuel?.type === 'composante' ? lienActuel.uuid : '',
      sousComposante: lienActuel?.type === 'sous_composante' ? lienActuel.uuid : '',
    },
  })

  const lien = watch('lien')

  const optionsSousComposantes = composantes.flatMap((c) =>
    (c.sousComposantes ?? []).map((sc) => ({ uuid: sc.uuid, libelle: `${c.code} — ${sc.code} — ${sc.libelle}` })),
  )

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      if (initiative) {
        // Lien PTBA envoyé en entier : les deux à null rendent le projet indépendant.
        await modifierInitiative(initiative.uuid, {
          libelle: valeurs.libelle,
          description: valeurs.description ?? '',
          composante: valeurs.lien === 'composante' ? (valeurs.composante ?? null) : null,
          sousComposante: valeurs.lien === 'sous_composante' ? (valeurs.sousComposante ?? null) : null,
        })
      } else {
        await creerInitiative({
          libelle: valeurs.libelle,
          description: valeurs.description === '' ? undefined : valeurs.description,
          composante: valeurs.lien === 'composante' ? valeurs.composante : undefined,
          sousComposante: valeurs.lien === 'sous_composante' ? valeurs.sousComposante : undefined,
        })
      }
      await queryClient.invalidateQueries({ queryKey: ['initiatives'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre={initiative ? 'Modifier le projet' : 'Nouveau projet'} onFermer={onFermer}>
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
          <label htmlFor="description">Description</label>
          <textarea
            id="description"
            rows={2}
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('description')}
          />
        </div>

        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">Lien avec le PTBA</legend>
          <label className="flex items-center gap-2 text-sm">
            <input type="radio" value="independant" {...register('lien')} /> Indépendant du PTBA
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input type="radio" value="composante" {...register('lien')} /> Lié à une composante
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input type="radio" value="sous_composante" {...register('lien')} /> Lié à une sous-composante
          </label>
        </fieldset>

        {'composante' === lien && (
          <div className="flex flex-col gap-1">
            <label htmlFor="composante">Composante</label>
            <select id="composante" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('composante')}>
              <option value="">— Choisir —</option>
              {composantes.map((c) => (
                <option key={c.uuid} value={c.uuid}>
                  {c.code} — {c.libelle}
                </option>
              ))}
            </select>
            {errors.composante && <p className="text-bad text-sm">{errors.composante.message}</p>}
          </div>
        )}

        {'sous_composante' === lien && (
          <div className="flex flex-col gap-1">
            <label htmlFor="sousComposante">Sous-composante</label>
            <select
              id="sousComposante"
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('sousComposante')}
            >
              <option value="">— Choisir —</option>
              {optionsSousComposantes.map((o) => (
                <option key={o.uuid} value={o.uuid}>
                  {o.libelle}
                </option>
              ))}
            </select>
            {errors.sousComposante && <p className="text-bad text-sm">{errors.sousComposante.message}</p>}
          </div>
        )}

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
            {initiative ? (isSubmitting ? 'Enregistrement…' : 'Enregistrer') : isSubmitting ? 'Création…' : 'Créer'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
