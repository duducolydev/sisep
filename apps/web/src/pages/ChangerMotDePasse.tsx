import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from 'react-router'
import { changerMotDePasse, deconnecter, profilQueryOptions } from '@/lib/auth'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { BandeauAnsd } from '@/components/BandeauAnsd'
import { Champ, IconeCadenas, IconeCle, IconeOeil, IconeOeilBarre, PanneauMarque } from './Connexion'

const schema = z
  .object({
    motDePasseActuel: z.string().min(1, 'Le mot de passe temporaire est requis.'),
    nouveauMotDePasse: z.string().min(12, 'Au moins 12 caractères.'),
    confirmation: z.string().min(1, 'Confirmez le nouveau mot de passe.'),
  })
  .refine((v) => v.nouveauMotDePasse === v.confirmation, {
    message: 'Les deux mots de passe ne correspondent pas.',
    path: ['confirmation'],
  })
  .refine((v) => v.nouveauMotDePasse !== v.motDePasseActuel, {
    message: 'Le nouveau mot de passe doit différer du mot de passe temporaire.',
    path: ['nouveauMotDePasse'],
  })

type FormValues = z.infer<typeof schema>

/**
 * Changement imposé d'un mot de passe temporaire (attribué à la création du
 * compte ou par une réinitialisation de l'administrateur). Le serveur refuse
 * toute donnée métier tant que le changement n'est pas fait
 * (ChangementMotDePasseRequisListener) ; cet écran n'est que le chemin pour
 * y parvenir, hors de la coquille de l'application qui n'aurait rien à
 * afficher.
 */
export function ChangerMotDePasse() {
  const navigate = useNavigate()
  const [erreur, setErreur] = useState<string | null>(null)
  const [visible, setVisible] = useState(false)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await changerMotDePasse(valeurs.motDePasseActuel, valeurs.nouveauMotDePasse)
      // Recharger, pas seulement invalider : aucun composant n'observe le
      // profil sur cet écran, une invalidation ne le referait pas, et le
      // routeur relirait l'ancien drapeau pour nous renvoyer ici.
      await queryClient.fetchQuery({ ...profilQueryOptions(), staleTime: 0 })
      await navigate('/tableau-de-bord', { replace: true })
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  async function seDeconnecter() {
    await deconnecter()
    queryClient.setQueryData(profilQueryOptions().queryKey, null)
    await navigate('/connexion', { replace: true })
  }

  const basculeVisibilite = (
    <button
      type="button"
      onClick={() => setVisible((v) => !v)}
      aria-label={visible ? 'Masquer les mots de passe' : 'Afficher les mots de passe'}
      className="flex items-center self-stretch border-l border-border px-4 text-muted transition-colors hover:text-accent"
    >
      {visible ? <IconeOeilBarre /> : <IconeOeil />}
    </button>
  )
  const classeSaisie = 'min-w-0 flex-1 bg-transparent px-4 py-3.5 text-text outline-none'

  return (
    <div className="page-connexion flex min-h-screen flex-col bg-surface">
      <BandeauAnsd hauteur="haute">
        <p className="font-titre text-lg leading-tight font-bold sm:text-2xl">SISEP</p>
        <p className="hidden text-xs tracking-[.08em] text-white/85 uppercase sm:block">Système de suivi-évaluation · Projet PHASAOC</p>
      </BandeauAnsd>
      <div className="grid flex-1 lg:grid-cols-[44%_1fr]">
      <PanneauMarque />

      <main className="flex flex-col">
        <header className="flex items-center justify-end px-6 pt-6 sm:px-12 lg:px-16">
          <button
            type="button"
            onClick={() => void seDeconnecter()}
            className="text-sm font-semibold text-muted transition-colors hover:text-accent"
          >
            Se déconnecter
          </button>
        </header>

        <div className="flex flex-1 items-center justify-center px-6 py-10 sm:px-12">
          <div className="w-full max-w-md">
            <h1 className="font-titre text-3xl font-extrabold text-navbg sm:text-4xl">Choisissez votre mot de passe</h1>
            <p className="mt-3 text-muted">
              Le mot de passe qui vous a été communiqué est temporaire. Remplacez-le par un mot de passe personnel
              d'au moins 12 caractères pour accéder à l'application.
            </p>

            {erreur && (
              <div role="alert" className="mt-6 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">
                {erreur}
              </div>
            )}

            <form onSubmit={handleSubmit(onSubmit)} noValidate className="mt-8 flex flex-col gap-6">
              <Champ
                id="motDePasseActuel"
                libelle="Mot de passe temporaire"
                icone={<IconeCle />}
                erreur={errors.motDePasseActuel?.message}
              >
                <input
                  id="motDePasseActuel"
                  type={visible ? 'text' : 'password'}
                  autoComplete="current-password"
                  aria-invalid={errors.motDePasseActuel ? 'true' : 'false'}
                  className={classeSaisie}
                  {...register('motDePasseActuel')}
                />
              </Champ>

              <Champ
                id="nouveauMotDePasse"
                libelle="Nouveau mot de passe"
                icone={<IconeCadenas />}
                erreur={errors.nouveauMotDePasse?.message}
                suffixe={basculeVisibilite}
              >
                <input
                  id="nouveauMotDePasse"
                  type={visible ? 'text' : 'password'}
                  autoComplete="new-password"
                  aria-invalid={errors.nouveauMotDePasse ? 'true' : 'false'}
                  className={classeSaisie}
                  {...register('nouveauMotDePasse')}
                />
              </Champ>

              <Champ
                id="confirmation"
                libelle="Confirmer le nouveau mot de passe"
                icone={<IconeCadenas />}
                erreur={errors.confirmation?.message}
              >
                <input
                  id="confirmation"
                  type={visible ? 'text' : 'password'}
                  autoComplete="new-password"
                  aria-invalid={errors.confirmation ? 'true' : 'false'}
                  className={classeSaisie}
                  {...register('confirmation')}
                />
              </Champ>

              <button
                type="submit"
                disabled={isSubmitting}
                className="mt-2 flex items-center justify-center gap-2.5 rounded-xl bg-gradient-to-r from-accent-d via-accent to-accent-clair py-4 font-titre text-lg font-bold text-white shadow-[0_14px_32px_-14px_var(--color-accent)] transition hover:brightness-110 disabled:opacity-60"
              >
                {isSubmitting ? 'Enregistrement…' : 'Enregistrer et continuer'}
              </button>
            </form>
          </div>
        </div>
      </main>
      </div>
    </div>
  )
}
