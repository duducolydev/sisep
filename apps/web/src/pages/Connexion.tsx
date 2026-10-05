import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from 'react-router'
import { connecter, profilQueryOptions } from '@/lib/auth'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  email: z.string().min(1, "L'adresse e-mail est requise.").email('Adresse e-mail invalide.'),
  motDePasse: z.string().min(1, 'Le mot de passe est requis.'),
})

type FormValues = z.infer<typeof schema>

export function Connexion() {
  const navigate = useNavigate()
  const [erreur, setErreur] = useState<string | null>(null)
  const [motDePasseVisible, setMotDePasseVisible] = useState(false)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      const profil = await connecter(valeurs.email, valeurs.motDePasse)
      queryClient.setQueryData(profilQueryOptions().queryKey, profil)
      await navigate('/tableau-de-bord', { replace: true })
    } catch (e) {
      // § 8.2 : bandeau d'erreur non bloquant, la saisie reste en place.
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <div
      className="page-connexion relative flex min-h-screen items-center justify-center overflow-hidden p-6"
      style={{ background: 'color-mix(in srgb, var(--color-navbg) 55%, black)' }}
    >
      {/* Halo ambiant derrière la carte — reprend l'effet néon de la maquette, dérivé des tokens accent, pas une couleur hors palette. */}
      <div
        className="pointer-events-none absolute h-[480px] w-[820px] max-w-full rounded-full bg-accent-clair/25 blur-[120px]"
        aria-hidden="true"
      />

      <div className="relative w-full max-w-3xl rounded-[28px] bg-gradient-to-br from-accent-clair via-accent to-accent-d p-[2px] shadow-[0_0_90px_-8px_var(--color-accent-clair),0_0_140px_-20px_var(--color-accent)]">
        <div className="relative overflow-hidden rounded-[26px] bg-navbg">
          {/*
            Pan bleu diagonal — § 9.2 : tokens accent/accent-clair de la
            maquette, jamais de couleur hors palette. Clip-path exprimé en
            pourcentages de la carte entière (pas d'un sous-panneau local) :
            le bord gauche du bleu recule vers la droite en descendant
            (55 % en haut → 66 % en bas), pour ne jamais recouvrir le texte
            de la colonne de gauche, y compris le paragraphe le plus bas.
          */}
          <div
            className="absolute inset-0 hidden bg-gradient-to-br from-accent to-accent-clair md:block"
            style={{ clipPath: 'polygon(55% 0%, 100% 0%, 100% 100%, 66% 100%)' }}
            aria-hidden="true"
          />

          <div className="relative grid gap-10 p-8 sm:p-12 md:grid-cols-[56%_44%] md:p-14">
            <div className="flex flex-col justify-center">
              <p className="mb-1 font-titre text-xs font-semibold uppercase tracking-[.2em] text-accent-clair">SISEP</p>
              <h1 className="mb-8 font-titre text-3xl font-extrabold text-navstrong">Connexion</h1>

              {erreur && (
                <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-sm text-bad">
                  {erreur}
                </div>
              )}

              <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-6">
                <div className="flex flex-col gap-2">
                  <div className="flex items-center justify-between gap-2">
                    <label htmlFor="email" className="text-navfg">
                      Adresse e-mail
                    </label>
                    <IconePersonne />
                  </div>
                  <input
                    id="email"
                    type="email"
                    autoComplete="email"
                    className="border-0 border-b border-navborder bg-transparent pb-2 text-white outline-none placeholder:text-navmuted focus:border-accent-clair"
                    aria-invalid={errors.email ? 'true' : 'false'}
                    {...register('email')}
                  />
                  {errors.email && <p className="text-sm text-bad">{errors.email.message}</p>}
                </div>

                <div className="flex flex-col gap-2">
                  <div className="flex items-center justify-between gap-2">
                    <label htmlFor="motDePasse" className="text-navfg">
                      Mot de passe
                    </label>
                    <button
                      type="button"
                      onClick={() => setMotDePasseVisible((v) => !v)}
                      aria-label={motDePasseVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                      className="text-navmuted hover:text-navstrong"
                    >
                      {motDePasseVisible ? <IconeOeilBarre /> : <IconeOeil />}
                    </button>
                  </div>
                  <input
                    id="motDePasse"
                    type={motDePasseVisible ? 'text' : 'password'}
                    autoComplete="current-password"
                    className="border-0 border-b border-navborder bg-transparent pb-2 text-white outline-none placeholder:text-navmuted focus:border-accent-clair"
                    aria-invalid={errors.motDePasse ? 'true' : 'false'}
                    {...register('motDePasse')}
                  />
                  {errors.motDePasse && <p className="text-sm text-bad">{errors.motDePasse.message}</p>}
                </div>

                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="mt-2 rounded-full bg-gradient-to-r from-accent to-accent-clair py-3 font-titre font-bold text-white shadow-[0_10px_30px_-10px_var(--color-accent)] transition-transform hover:brightness-110 disabled:opacity-60"
                >
                  {isSubmitting ? 'Connexion…' : 'Se connecter'}
                </button>
              </form>

              <p className="mt-5 text-sm text-navmuted">
                Accès réservé aux agents habilités — contactez l'administrateur SISEP en cas de difficulté.
              </p>
            </div>

            {/*
              La colonne fait 44 % de la carte (grid-cols-[56%_44%]) et
              commence donc à l'abscisse 56 % — pile où le bleu démarre en
              haut du dégradé diagonal. Le bleu recule jusqu'à 66 % en bas
              (cf. clip-path ci-dessus) : pl-[30%] de cette colonne de 44 %
              pousse le texte à ~69 % de la carte, toujours au-delà du bleu,
              quelle que soit la hauteur — le texte ne doit jamais apparaître
              sur le fond sombre.
            */}
            <div className="flex flex-col justify-center md:pl-[30%] md:text-white">
              <h2 className="font-titre text-4xl font-extrabold text-navstrong md:text-white">
                Bon retour&nbsp;!
              </h2>
              <p className="mt-4 max-w-xs text-navfg md:text-white/85">
                Suivi-évaluation du PHASAOC — retrouvez l'état d'exécution du projet, le budget et les indicateurs de
                performance du cadre de résultats.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

const iconeProps = {
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.7,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  className: 'h-4 w-4 text-navmuted',
}

function IconePersonne() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <circle cx="12" cy="8" r="3.4" />
      <path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6" />
    </svg>
  )
}

function IconeOeil() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M1.5 12S5 5 12 5s10.5 7 10.5 7-3.5 7-10.5 7S1.5 12 1.5 12Z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  )
}

function IconeOeilBarre() {
  return (
    <svg {...iconeProps} aria-hidden="true">
      <path d="M3 3l18 18" />
      <path d="M10.6 5.2C11.05 5.07 11.52 5 12 5c7 0 10.5 7 10.5 7a13.5 13.5 0 0 1-3.15 4.15M6.5 6.65C3.6 8.5 1.5 12 1.5 12s3.5 7 10.5 7c1.28 0 2.46-.23 3.53-.63" />
      <path d="M9.5 9.6a3 3 0 0 0 4.15 4.24" />
    </svg>
  )
}
