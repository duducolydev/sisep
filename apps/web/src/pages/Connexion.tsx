import { useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from 'react-router'
import { connecter, profilQueryOptions } from '@/lib/auth'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { BandeauAnsd } from '@/components/BandeauAnsd'
import logoAnsd from '@/assets/ansd/logo-ansd.png'

const schema = z.object({
  email: z.string().min(1, "L'adresse e-mail est requise.").email('Adresse e-mail invalide.'),
  motDePasse: z.string().min(1, 'Le mot de passe est requis.'),
})

type FormValues = z.infer<typeof schema>

/**
 * Page de connexion : écran partagé, panneau de marque à gauche, formulaire
 * à droite.
 *
 * Le panneau reprend le bleu nuit de la barre latérale de l'application,
 * pour que l'utilisateur reconnaisse, une fois connecté, l'endroit où il
 * vient d'entrer. Il disparaît sous la largeur d'une tablette : sur un
 * téléphone, le formulaire passe avant la marque.
 */
export function Connexion() {
  const navigate = useNavigate()
  const [erreur, setErreur] = useState<string | null>(null)
  const [motDePasseVisible, setMotDePasseVisible] = useState(false)
  const [aideOuverte, setAideOuverte] = useState(false)
  const annee = new Date().getFullYear()

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
      await navigate(profil.doitChangerMdp ? '/changer-mot-de-passe' : '/tableau-de-bord', { replace: true })
    } catch (e) {
      // § 8.2 : bandeau d'erreur non bloquant, la saisie reste en place.
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <div className="page-connexion flex min-h-screen flex-col bg-surface">
      <BandeauAnsd hauteur="haute">
        <p className="font-titre text-lg leading-tight font-bold sm:text-2xl">SISEP</p>
        <p className="hidden text-xs tracking-[.08em] text-white/85 uppercase sm:block">Système de suivi-évaluation · Projet PHASAOC</p>
      </BandeauAnsd>
      <div className="grid flex-1 lg:grid-cols-[44%_1fr]">
      <PanneauMarque />

      <main className="flex flex-col">

        <div className="flex flex-1 items-center justify-center px-6 py-10 sm:px-12">
          <div className="w-full max-w-md">
            <h1 className="font-titre text-4xl font-extrabold text-navbg sm:text-5xl">Bienvenue&nbsp;!</h1>
            <p className="mt-3 text-muted">
              Connectez-vous pour accéder à la plateforme de suivi-évaluation du projet PHASAOC.
            </p>

            {erreur && (
              <div role="alert" className="mt-6 rounded-xl bg-bad-bg px-4 py-3 text-sm text-bad">
                {erreur}
              </div>
            )}

            <form onSubmit={handleSubmit(onSubmit)} noValidate className="mt-8 flex flex-col gap-6">
              <Champ
                id="email"
                libelle="Adresse e-mail"
                icone={<IconePersonne />}
                erreur={errors.email?.message}
              >
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

              <div>
                <Champ
                  id="motDePasse"
                  libelle="Mot de passe"
                  icone={<IconeCadenas />}
                  erreur={errors.motDePasse?.message}
                  suffixe={
                    <button
                      type="button"
                      onClick={() => setMotDePasseVisible((v) => !v)}
                      aria-label={motDePasseVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                      className="flex items-center self-stretch border-l border-border px-4 text-muted transition-colors hover:text-accent"
                    >
                      {motDePasseVisible ? <IconeOeilBarre /> : <IconeOeil />}
                    </button>
                  }
                >
                  <input
                    id="motDePasse"
                    type={motDePasseVisible ? 'text' : 'password'}
                    autoComplete="current-password"
                    aria-invalid={errors.motDePasse ? 'true' : 'false'}
                    className="min-w-0 flex-1 bg-transparent px-4 py-3.5 text-text outline-none"
                    {...register('motDePasse')}
                  />
                </Champ>

                <div className="mt-3 flex justify-end">
                  {/*
                    Pas de lien de réinitialisation : SISEP n'a pas de
                    parcours de récupération en libre-service, et un lien
                    vers nulle part vaut moins qu'une consigne juste. Le
                    recours réel est l'administrateur, qui attribue un mot
                    de passe temporaire à changer à la connexion suivante.
                  */}
                  <button
                    type="button"
                    onClick={() => setAideOuverte((v) => !v)}
                    aria-expanded={aideOuverte}
                    aria-controls="aide-mot-de-passe"
                    className="flex items-center gap-1.5 text-sm font-semibold text-accent transition-colors hover:text-accent-d"
                  >
                    <IconeCle />
                    Mot de passe oublié&nbsp;?
                  </button>
                </div>
                {aideOuverte && (
                  <p id="aide-mot-de-passe" className="mt-2 rounded-xl bg-accent-bg px-4 py-3 text-sm text-accent-d">
                    Contactez l'administrateur SISEP de l'UGP : il vous attribuera un mot de passe temporaire, que
                    l'application vous demandera de changer dès votre connexion.
                  </p>
                )}
              </div>

              <button
                type="submit"
                disabled={isSubmitting}
                className="mt-2 flex items-center justify-center gap-2.5 rounded-xl bg-gradient-to-r from-accent-d via-accent to-accent-clair py-4 font-titre text-lg font-bold text-white shadow-[0_14px_32px_-14px_var(--color-accent)] transition hover:brightness-110 disabled:opacity-60"
              >
                <IconeEntrer />
                {isSubmitting ? 'Connexion…' : 'Se connecter'}
              </button>
            </form>

            <p className="mt-8 text-center text-sm text-muted">
              Accès réservé aux agents habilités — Visitez{' '}
              <a
                href="https://www.ansd.sn"
                target="_blank"
                rel="noopener noreferrer"
                className="font-semibold text-accent hover:underline"
              >
                ansd.sn
              </a>
            </p>
          </div>
        </div>

        <footer className="border-t border-border px-6 py-5 text-center text-xs text-muted">
          © {annee} Agence Nationale de la Statistique et de la Démographie — Tous droits réservés
        </footer>
      </main>
      </div>
    </div>
  )
}

/**
 * Panneau de marque. Les cercles et la trame de points sont décoratifs et
 * masqués aux lecteurs d'écran ; seul le texte porte de l'information.
 */
export function PanneauMarque() {
  return (
    <aside
      className="relative hidden flex-col justify-between overflow-hidden p-10 text-white lg:flex xl:p-12"
      style={{
        background:
          'linear-gradient(160deg, var(--color-navbg) 0%, color-mix(in srgb, var(--color-navbg) 55%, var(--color-accent-d)) 45%, var(--color-accent-d) 100%)',
      }}
    >
      {/* Trame de points, très discrète : elle donne une texture au fond sans attirer l'œil. */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 opacity-[.07]"
        style={{ backgroundImage: 'radial-gradient(white 1px, transparent 1px)', backgroundSize: '22px 22px' }}
      />
      <Cercle className="-top-24 right-[-18%] h-[26rem] w-[26rem]" />
      <Cercle className="top-[18%] left-[12%] h-56 w-56" />
      <Cercle className="right-[12%] bottom-[30%] h-28 w-28" />
      <Cercle className="-bottom-20 -left-24 h-72 w-72" />


      <div className="relative flex flex-col items-center text-center">
        <div className="rounded-[1.75rem] bg-white p-5 shadow-[0_18px_40px_-18px_rgb(0_0_0/0.5)]">
          <img src={logoAnsd} alt="ANSD" className="h-28 w-auto xl:h-32" />
        </div>
        <p className="mt-8 max-w-sm font-titre text-3xl leading-tight font-extrabold xl:text-[2rem]">
          Agence Nationale de la Statistique et de la Démographie
        </p>
        <p className="mt-4 text-sm tracking-[.18em] text-white/60">PROJET PHASAOC</p>
      </div>

      <div className="relative flex items-center gap-4 rounded-2xl border border-white/15 bg-white/[.06] p-4 backdrop-blur-sm">
        <span className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white p-0.5">
          <img src={logoAnsd} alt="" className="h-full w-full object-contain" />
        </span>
        <p className="flex-1 text-[11px] leading-relaxed font-semibold tracking-[.12em] text-white/75">
          HARMONISATION ET AMÉLIORATION DES STATISTIQUES
          <br />
          EN AFRIQUE DE L'OUEST ET DU CENTRE
        </p>
        <DrapeauSenegal />
      </div>
    </aside>
  )
}

function Cercle({ className }: { className: string }) {
  return (
    <div
      aria-hidden="true"
      className={`pointer-events-none absolute rounded-full border border-white/10 bg-white/[.04] ${className}`}
    />
  )
}

/** Drapeau du Sénégal : trois bandes verticales, étoile verte au centre de la bande jaune. */
function DrapeauSenegal() {
  return (
    <svg viewBox="0 0 90 60" className="h-11 w-16 shrink-0 overflow-hidden rounded-md shadow-sm" role="img" aria-label="Drapeau du Sénégal">
      <rect width="30" height="60" fill="#00853F" />
      <rect x="30" width="30" height="60" fill="#FDEF42" />
      <rect x="60" width="30" height="60" fill="#E31B23" />
      <path d="M45 19l3.1 9.4h9.9l-8 5.8 3.1 9.4-8.1-5.8-8.1 5.8 3.1-9.4-8-5.8h9.9z" fill="#00853F" />
    </svg>
  )
}

/**
 * Champ à cellule d'icône : l'icône vit dans sa propre case, séparée de la
 * saisie par un filet, comme un préfixe. Tout le cadre réagit au focus,
 * pas seulement le texte.
 */
export function Champ({
  id,
  libelle,
  icone,
  suffixe,
  erreur,
  children,
}: {
  id: string
  libelle: string
  icone: ReactNode
  suffixe?: ReactNode
  erreur?: string
  children: ReactNode
}) {
  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id} className="text-xs font-bold tracking-[.14em] text-navbg uppercase">
        {libelle}
      </label>
      <div
        className={`flex items-stretch overflow-hidden rounded-xl border-2 bg-accent-bg/60 transition focus-within:border-accent focus-within:bg-surface focus-within:ring-4 focus-within:ring-accent/10 ${
          erreur ? 'border-bad' : 'border-border'
        }`}
      >
        <span className="flex items-center border-r border-border bg-surface/70 px-4 text-muted" aria-hidden="true">
          {icone}
        </span>
        {children}
        {suffixe}
      </div>
      {erreur && <p className="text-sm text-bad">{erreur}</p>}
    </div>
  )
}

const iconeProps = {
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.8,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  className: 'h-[18px] w-[18px]',
  'aria-hidden': true,
}

function IconePersonne() {
  return (
    <svg {...iconeProps}>
      <circle cx="12" cy="8" r="3.4" />
      <path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6" />
    </svg>
  )
}

export function IconeCadenas() {
  return (
    <svg {...iconeProps}>
      <rect x="5" y="10.5" width="14" height="10" rx="2" />
      <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" />
    </svg>
  )
}

export function IconeCle() {
  return (
    <svg {...iconeProps} className="h-4 w-4">
      <circle cx="8" cy="15" r="4" />
      <path d="M10.85 12.15 19 4M15.5 7.5 18 10M17 5.5 19.5 8" />
    </svg>
  )
}

function IconeEntrer() {
  return (
    <svg {...iconeProps} className="h-5 w-5">
      <path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" />
      <path d="M3 12h11M10 8l4 4-4 4" />
    </svg>
  )
}

export function IconeOeil() {
  return (
    <svg {...iconeProps}>
      <path d="M1.5 12S5 5 12 5s10.5 7 10.5 7-3.5 7-10.5 7S1.5 12 1.5 12Z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  )
}

export function IconeOeilBarre() {
  return (
    <svg {...iconeProps}>
      <path d="M3 3l18 18" />
      <path d="M10.6 5.2C11.05 5.07 11.52 5 12 5c7 0 10.5 7 10.5 7a13.5 13.5 0 0 1-3.15 4.15M6.5 6.65C3.6 8.5 1.5 12 1.5 12s3.5 7 10.5 7c1.28 0 2.46-.23 3.53-.63" />
      <path d="M9.5 9.6a3 3 0 0 0 4.15 4.24" />
    </svg>
  )
}
