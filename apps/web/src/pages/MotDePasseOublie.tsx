import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link } from 'react-router'
import { demanderRecuperation } from '@/lib/auth'
import { ApiError } from '@/lib/api-client'
import { CadreAcces, Champ, IconePersonne } from './Connexion'

const schema = z.object({
  email: z.string().min(1, "L'adresse e-mail est requise.").email('Adresse e-mail invalide.'),
})

type FormValues = z.infer<typeof schema>

/**
 * Demande d'un lien de récupération. La confirmation est la même, qu'un
 * compte corresponde à l'adresse ou non : le serveur ne le dit pas, et
 * l'écran ne doit pas le laisser deviner.
 */
export function MotDePasseOublie() {
  const [erreur, setErreur] = useState<string | null>(null)
  const [envoyeA, setEnvoyeA] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await demanderRecuperation(valeurs.email)
      setEnvoyeA(valeurs.email)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <CadreAcces>
      <h1 className="font-titre text-3xl font-extrabold text-navbg sm:text-4xl">Mot de passe oublié</h1>

      {envoyeA ? (
        <div className="mt-6 flex flex-col gap-4">
          <p role="status" className="rounded-xl bg-accent-bg px-4 py-3 text-sm text-accent-d">
            Si un compte SISEP correspond à <span className="font-semibold break-all">{envoyeA}</span>, un courriel
            vient de lui être envoyé. Le lien qu'il contient est valable une heure et ne sert qu'une fois.
          </p>
          <p className="text-sm text-muted">
            Rien reçu après quelques minutes&nbsp;? Vérifiez vos courriers indésirables, puis l'adresse saisie. Si le
            problème persiste, l'administrateur SISEP de l'UGP peut vous attribuer un mot de passe temporaire.
          </p>
        </div>
      ) : (
        <>
          <p className="mt-3 text-muted">
            Indiquez l'adresse e-mail de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.
          </p>

          {erreur && (
            <div role="alert" className="mt-6 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">
              {erreur}
            </div>
          )}

          <form onSubmit={handleSubmit(onSubmit)} noValidate className="mt-8 flex flex-col gap-6">
            <Champ id="email" libelle="Adresse e-mail" icone={<IconePersonne />} erreur={errors.email?.message}>
              <input
                id="email"
                type="email"
                autoComplete="email"
                placeholder="prenom.nom@ansd.sn"
                aria-invalid={errors.email ? 'true' : 'false'}
                className="min-w-0 flex-1 bg-transparent px-4 py-3.5 text-text outline-none placeholder:text-muted/70"
                {...register('email')}
              />
            </Champ>

            <button
              type="submit"
              disabled={isSubmitting}
              className="flex items-center justify-center gap-2.5 rounded-xl bg-gradient-to-r from-accent-d via-accent to-accent-clair py-4 font-titre text-lg font-bold text-white shadow-[0_14px_32px_-14px_var(--color-accent)] transition hover:brightness-110 disabled:opacity-60"
            >
              {isSubmitting ? 'Envoi…' : 'Recevoir un lien'}
            </button>
          </form>
        </>
      )}

      <p className="mt-8 text-center text-sm">
        <Link to="/connexion" className="font-semibold text-accent hover:underline">
          Retour à la connexion
        </Link>
      </p>
    </CadreAcces>
  )
}
