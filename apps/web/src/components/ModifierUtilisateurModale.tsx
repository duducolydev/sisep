import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { modifierUtilisateur, type Utilisateur } from '@/lib/utilisateurs'
import { structuresDetailQueryOptions } from '@/lib/parties-prenantes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  prenom: z.string().min(1, 'Le prénom est requis.'),
  nom: z.string().min(1, 'Le nom est requis.'),
  fonction: z.string().optional(),
  structureUuid: z.string().optional(),
  actif: z.boolean(),
})

type FormValues = z.infer<typeof schema>

interface ModifierUtilisateurModaleProps {
  utilisateur: Utilisateur
  onFermer: () => void
}

/** Édition d'un compte de connexion — email non modifiable ici (identifiant de connexion, § 4.5). */
export function ModifierUtilisateurModale({ utilisateur, onFermer }: ModifierUtilisateurModaleProps) {
  const { data: structures } = useQuery(structuresDetailQueryOptions())
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      prenom: utilisateur.prenom,
      nom: utilisateur.nom,
      fonction: utilisateur.fonction ?? '',
      structureUuid: utilisateur.structure?.uuid ?? '',
      actif: utilisateur.actif,
    },
  })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await modifierUtilisateur(utilisateur.uuid, {
        prenom: valeurs.prenom,
        nom: valeurs.nom,
        fonction: valeurs.fonction || null,
        structureUuid: valeurs.structureUuid || null,
        actif: valeurs.actif,
      })
      await queryClient.invalidateQueries({ queryKey: ['admin', 'utilisateurs'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre={`Modifier ${utilisateur.prenom} ${utilisateur.nom}`} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <p className="text-sm text-muted">{utilisateur.email}</p>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="prenom">Prénom</label>
            <input id="prenom" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('prenom')} />
            {errors.prenom && <p className="text-bad text-sm">{errors.prenom.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="nom">Nom</label>
            <input id="nom" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('nom')} />
            {errors.nom && <p className="text-bad text-sm">{errors.nom.message}</p>}
          </div>
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="fonction">Fonction (optionnel)</label>
          <input id="fonction" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('fonction')} />
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="structureUuid">Structure (optionnel)</label>
          <select
            id="structureUuid"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('structureUuid')}
          >
            <option value="">Aucune</option>
            {structures?.donnees.map((s) => (
              <option key={s.uuid} value={s.uuid}>
                {s.sigle}
              </option>
            ))}
          </select>
        </div>

        <label className="flex items-center gap-2">
          <input type="checkbox" {...register('actif')} />
          Compte actif
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
