import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { changerMotDePasse, profilQueryOptions } from '@/lib/auth'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { useToast } from './ToastProvider'

const schema = z
  .object({
    motDePasseActuel: z.string().min(1, 'Le mot de passe actuel est requis.'),
    nouveauMotDePasse: z.string().min(12, 'Au moins 12 caractères.'),
    confirmation: z.string().min(1, 'Confirmez le nouveau mot de passe.'),
  })
  .refine((v) => v.nouveauMotDePasse === v.confirmation, { message: 'Les deux mots de passe ne correspondent pas.', path: ['confirmation'] })

type FormValues = z.infer<typeof schema>

interface ModifierMotDePasseModaleProps {
  onFermer: () => void
}

/** Auto-service — App\Controller\AuthController::changerMotDePasse(), distinct de la réinitialisation par un administrateur pour un tiers (§ 4.5, § 7). */
export function ModifierMotDePasseModale({ onFermer }: ModifierMotDePasseModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)
  const afficherToast = useToast()

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await changerMotDePasse(valeurs.motDePasseActuel, valeurs.nouveauMotDePasse)
      await queryClient.invalidateQueries({ queryKey: profilQueryOptions().queryKey })
      afficherToast('Mot de passe modifié.')
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Modifier le mot de passe" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="motDePasseActuel">Mot de passe actuel</label>
          <input
            id="motDePasseActuel"
            type="password"
            autoComplete="current-password"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('motDePasseActuel')}
          />
          {errors.motDePasseActuel && <p className="text-bad text-sm">{errors.motDePasseActuel.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="nouveauMotDePasse">Nouveau mot de passe</label>
          <input
            id="nouveauMotDePasse"
            type="password"
            autoComplete="new-password"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('nouveauMotDePasse')}
          />
          {errors.nouveauMotDePasse && <p className="text-bad text-sm">{errors.nouveauMotDePasse.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="confirmation">Confirmer le nouveau mot de passe</label>
          <input
            id="confirmation"
            type="password"
            autoComplete="new-password"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('confirmation')}
          />
          {errors.confirmation && <p className="text-bad text-sm">{errors.confirmation.message}</p>}
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
            {isSubmitting ? 'Enregistrement…' : 'Modifier'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
