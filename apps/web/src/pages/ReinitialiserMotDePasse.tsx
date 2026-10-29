import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link, useLocation, useNavigate } from 'react-router'
import { reinitialiserMotDePasse } from '@/lib/auth'
import { ApiError } from '@/lib/api-client'
import { CadreAcces, Champ, IconeCadenas, IconeOeil, IconeOeilBarre } from './Connexion'

const schema = z
  .object({
    nouveauMotDePasse: z.string().min(12, 'Au moins 12 caractères.'),
    confirmation: z.string().min(1, 'Confirmez le nouveau mot de passe.'),
  })
  .refine((v) => v.nouveauMotDePasse === v.confirmation, {
    message: 'Les deux mots de passe ne correspondent pas.',
    path: ['confirmation'],
  })

type FormValues = z.infer<typeof schema>

const classeSaisie = 'min-w-0 flex-1 bg-transparent px-4 py-3.5 text-text outline-none'

/**
 * Arrivée depuis le lien du courriel de récupération. Le jeton est dans le
 * fragment de l'adresse (`#…`), que le navigateur n'envoie jamais au
 * serveur ; il est lu une fois, puis retiré de la barre d'adresse.
 */
export function ReinitialiserMotDePasse() {
  const location = useLocation()
  const navigate = useNavigate()
  const [jeton] = useState(() => location.hash.slice(1))
  const [erreur, setErreur] = useState<string | null>(null)
  const [lienInvalide, setLienInvalide] = useState(false)
  const [termine, setTermine] = useState(false)
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    if (location.hash !== '') {
      void navigate(location.pathname, { replace: true })
    }
  }, [location.hash, location.pathname, navigate])

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await reinitialiserMotDePasse(jeton, valeurs.nouveauMotDePasse)
      setTermine(true)
    } catch (e) {
      // Lien périmé, déjà servi ou remplacé : le formulaire ne sert plus à
      // rien, seul un nouveau lien débloque la situation.
      if (e instanceof ApiError && e.status === 422 && !Array.isArray((e.probleme as { violations?: unknown })?.violations)) {
        setLienInvalide(true)
      }
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  if (termine) {
    return (
      <CadreAcces>
        <h1 className="font-titre text-3xl font-extrabold text-navbg sm:text-4xl">Mot de passe modifié</h1>
        <p role="status" className="mt-6 rounded-xl bg-accent-bg px-4 py-3 text-sm text-accent-d">
          Votre nouveau mot de passe est enregistré. Un courriel de confirmation vous a été envoyé.
        </p>
        <Link
          to="/connexion"
          className="mt-8 flex items-center justify-center rounded-xl bg-gradient-to-r from-accent-d via-accent to-accent-clair py-4 font-titre text-lg font-bold text-white shadow-[0_14px_32px_-14px_var(--color-accent)] transition hover:brightness-110"
        >
          Se connecter
        </Link>
      </CadreAcces>
    )
  }

  if (jeton === '' || lienInvalide) {
    return (
      <CadreAcces>
        <h1 className="font-titre text-3xl font-extrabold text-navbg sm:text-4xl">Lien inutilisable</h1>
        <p role="alert" className="mt-6 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">
          {erreur ?? "Ce lien de récupération est incomplet. Ouvrez-le tel qu'il figure dans le courriel, ou demandez-en un nouveau."}
        </p>
        <p className="mt-8 flex flex-col items-center gap-3 text-sm">
          <Link to="/mot-de-passe-oublie" className="font-semibold text-accent hover:underline">
            Demander un nouveau lien
          </Link>
          <Link to="/connexion" className="text-muted hover:text-accent">
            Retour à la connexion
          </Link>
        </p>
      </CadreAcces>
    )
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

  return (
    <CadreAcces>
      <h1 className="font-titre text-3xl font-extrabold text-navbg sm:text-4xl">Choisissez un nouveau mot de passe</h1>
      <p className="mt-3 text-muted">Au moins 12 caractères. Il remplacera aussitôt l'ancien.</p>

      {erreur && (
        <div role="alert" className="mt-6 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="mt-8 flex flex-col gap-6">
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
          className="flex items-center justify-center gap-2.5 rounded-xl bg-gradient-to-r from-accent-d via-accent to-accent-clair py-4 font-titre text-lg font-bold text-white shadow-[0_14px_32px_-14px_var(--color-accent)] transition hover:brightness-110 disabled:opacity-60"
        >
          {isSubmitting ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </form>
    </CadreAcces>
  )
}
