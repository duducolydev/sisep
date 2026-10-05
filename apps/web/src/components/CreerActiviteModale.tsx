import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from 'react-router'
import { Modale } from './Modale'
import { creerActivite, type NoeudComposante, type Structure } from '@/lib/composantes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  sousComposante: z.string().min(1, 'La sous-composante est requise.'),
  code: z.string().min(1, 'Le code est requis.').max(20),
  libelle: z.string().min(1, 'Le libellé est requis.'),
  structureResponsable: z.string().min(1, 'La structure responsable est requise.'),
})

type FormValues = z.infer<typeof schema>

interface CreerActiviteModaleProps {
  projetUuid: string
  composantes: NoeudComposante[]
  structures: Structure[]
  onFermer: () => void
}

export function CreerActiviteModale({ composantes, structures, onFermer }: CreerActiviteModaleProps) {
  const navigate = useNavigate()
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      const activite = await creerActivite(valeurs)
      await queryClient.invalidateQueries({ queryKey: ['arbre'] })
      onFermer()
      await navigate(`/composantes/activites/${activite.uuid}`)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  const optionsSousComposantes = composantes.flatMap((c) =>
    (c.sousComposantes ?? []).map((sc) => ({
      uuid: sc.uuid,
      libelle: `${c.code} — ${sc.code} — ${sc.libelle}`,
    })),
  )

  return (
    <Modale titre="Créer une activité" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
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

        <div className="flex flex-col gap-1">
          <label htmlFor="code">Code</label>
          <input id="code" className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('code')} />
          {errors.code && <p className="text-bad text-sm">{errors.code.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="libelle">Libellé</label>
          <input id="libelle" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('libelle')} />
          {errors.libelle && <p className="text-bad text-sm">{errors.libelle.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="structureResponsable">Structure responsable</label>
          <select
            id="structureResponsable"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('structureResponsable')}
          >
            <option value="">— Choisir —</option>
            {structures.map((s) => (
              <option key={s.uuid} value={s.uuid}>
                {s.sigle} — {s.denomination}
              </option>
            ))}
          </select>
          {errors.structureResponsable && <p className="text-bad text-sm">{errors.structureResponsable.message}</p>}
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
