import { useState, type ReactNode } from 'react'
import { Link, Outlet, useLocation, useNavigate } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { deconnecter, detientGlobalement, profilQueryOptions } from '@/lib/auth'
import { entreeAdministration } from '@/lib/admin'
import { useUtilisateurCourant } from '@/lib/use-permissions'
import { queryClient } from '@/lib/query-client'
import { budgetExecutionQueryOptions } from '@/lib/budget'
import { indicateursQueryOptions } from '@/lib/indicateurs'
import { structuresQueryOptions } from '@/lib/composantes'
import { initiativesQueryOptions } from '@/lib/initiatives'
import { ToastProvider } from './ToastProvider'
import { ConfirmationProvider } from './ConfirmationProvider'
import { BandeauAnsd } from './BandeauAnsd'
import logoAnsd from '@/assets/ansd/logo-ansd.png'
import { MenuUtilisateur } from './MenuUtilisateur'
import { ModifierMotDePasseModale } from './ModifierMotDePasseModale'

const CLE_REPLI = 'sisep.sidebar.repliee'
const LARGEUR_OUVERTE = 238
const LARGEUR_REPLIEE = 68

const iconProps = {
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.7,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
}

const ICONES: Record<string, ReactNode> = {
  tableauDeBord: (
    <svg {...iconProps}>
      <path d="M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z" />
    </svg>
  ),
  composantes: (
    <svg {...iconProps}>
      <path d="M4 5h16M4 12h11M4 19h14" />
    </svg>
  ),
  budget: (
    <svg {...iconProps}>
      <path d="M4 19V9M10 19V4M16 19v-7M2 19h20" />
    </svg>
  ),
  execution: (
    <svg {...iconProps}>
      <path d="M4 18a8 8 0 1 1 16 0M12 18l4-6M4 18h16" />
    </svg>
  ),
  indicateurs: (
    <svg {...iconProps}>
      <path d="M3 17l5-6 4 3 5-8 4 5" />
    </svg>
  ),
  diligences: (
    <svg {...iconProps}>
      <path d="M9 11l3 3 8-8" />
      <path d="M21 12v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h11" />
    </svg>
  ),
  partiesPrenantes: (
    <svg {...iconProps}>
      <circle cx="9" cy="8" r="3.2" />
      <path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5" />
      <circle cx="17.5" cy="9" r="2.4" />
      <path d="M16 14.6c3 .2 5 2.3 5 5.4" />
    </svg>
  ),
  ressourcesHumaines: (
    <svg {...iconProps}>
      <circle cx="9" cy="8" r="3.2" />
      <path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5" />
      <path d="M16 3.5a3.2 3.2 0 0 1 0 6.1" />
      <path d="M19 20c0-2.6-1.2-4.4-3-5.2" />
    </svg>
  ),
  documents: (
    <svg {...iconProps}>
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
      <path d="M14 3v5h5M9 13h6M9 17h4" />
    </svg>
  ),
  administration: (
    <svg {...iconProps}>
      <circle cx="12" cy="12" r="3.2" />
      <path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1" />
    </svg>
  ),
}

interface LienNav {
  to: string
  label: string
  icone: keyof typeof ICONES
  compte?: number
}

function lireRepliInitial(): boolean {
  if (typeof window === 'undefined') return false

  return window.localStorage.getItem(CLE_REPLI) === '1'
}

export function Layout() {
  const navigate = useNavigate()
  const location = useLocation()
  const { data: utilisateur } = useUtilisateurCourant()
  const [repliee, setRepliee] = useState(lireRepliInitial)
  const [modaleMotDePasse, setModaleMotDePasse] = useState(false)

  const { data: composantes } = useQuery(budgetExecutionQueryOptions('composante'))
  const { data: structures } = useQuery(structuresQueryOptions())
  // Module Projets réservé à l'UGP (décision du 26/10/2026).
  const voitProjets = utilisateur?.permissionsPortee['initiatives.consulter'] === null
  const { data: initiatives } = useQuery({ ...initiativesQueryOptions(), enabled: voitProjets })

  // Masquage uniquement cosmétique (§ 8) : l'accès réel est tranché côté
  // serveur. Le lien suit les onglets de la page (lib/admin.ts) et pointe sur
  // le premier ouvert : un coordonnateur sans indicateurs.definir était
  // auparavant renvoyé vers le tableau de bord par un lien figé sur cet
  // onglet, et un administrateur limité à une structure voyait un lien dont
  // chaque onglet répondait 403.
  const entreeAdmin = entreeAdministration(utilisateur)
  // Module réservé par défaut à l'équipe PHASAOC (§ décision), sauf
  // invitation ponctuelle d'un sectoriel sur un indicateur précis — un
  // utilisateur invité a un accès serveur réel mais ne détient jamais
  // indicateurs.consulter en liste de permissions ; utiliser directement le
  // champ dédié (aAccesIndicateurs), même classe de correction que le bug
  // de navigation « Administration » déjà rencontré (§ 8).
  const voitIndicateurs = utilisateur?.aAccesIndicateurs ?? false
  // Le suivi RH porte des données contractuelles : réservé à l'UGP.
  const voitRh = detientGlobalement(utilisateur, 'rh.consulter')
  // Parties prenantes : équipe du projet (droit global). Un point focal n'y a
  // plus accès (décision du 26/10/2026) ; il transmet les rapports de sa
  // structure depuis « Mes rapports » (droit limité à sa structure).
  const voitPartiesPrenantes = utilisateur?.permissionsPortee['parties_prenantes.consulter'] === null
  const voitMesRapports = Array.isArray(utilisateur?.permissionsPortee['rapports.transmettre'])
  const { data: indicateurs } = useQuery({ ...indicateursQueryOptions(), enabled: voitIndicateurs })

  const liensPilotage: LienNav[] = [
    { to: '/tableau-de-bord', label: 'Tableau de bord', icone: 'tableauDeBord' },
    { to: '/composantes', label: 'Composantes', icone: 'composantes', compte: composantes?.donnees.length },
    { to: '/budget', label: 'Budget', icone: 'budget' },
    { to: '/execution', label: 'Exécution', icone: 'execution' },
    ...(voitIndicateurs
      ? [{ to: '/indicateurs', label: 'Indicateurs de performance', icone: 'indicateurs' as const, compte: indicateurs?.donnees.length }]
      : []),
    ...(voitProjets ? [{ to: '/initiatives', label: 'Projets', icone: 'diligences' as const, compte: initiatives?.donnees.length }] : []),
    { to: '/documents', label: 'Documents', icone: 'documents' },
  ]

  const liensActeurs: LienNav[] = [
    ...(voitPartiesPrenantes
      ? [{ to: '/parties-prenantes', label: 'Parties prenantes', icone: 'partiesPrenantes' as const, compte: structures?.donnees.length }]
      : []),
    ...(voitMesRapports ? [{ to: '/mes-rapports', label: 'Mes rapports', icone: 'documents' as const }] : []),
    ...(voitRh ? [{ to: '/ressources-humaines', label: 'Ressources humaines', icone: 'ressourcesHumaines' as const }] : []),
    ...(entreeAdmin !== null ? [{ to: entreeAdmin, label: 'Administration', icone: 'administration' as const }] : []),
  ]

  function estActif(to: string): boolean {
    const racine = to.split('/').slice(0, 2).join('/')

    return location.pathname === to || location.pathname.startsWith(`${racine}/`) || location.pathname === racine
  }

  function basculerRepli() {
    setRepliee((precedent) => {
      const suivant = !precedent
      window.localStorage.setItem(CLE_REPLI, suivant ? '1' : '0')

      return suivant
    })
  }

  async function seDeconnecter() {
    await deconnecter()
    queryClient.setQueryData(profilQueryOptions().queryKey, null)
    await navigate('/connexion', { replace: true })
  }

  return (
    <ToastProvider>
      <ConfirmationProvider>
      <div className="min-h-screen overflow-x-hidden bg-bg text-text">
        <nav
          aria-label="Navigation principale"
          style={{ width: repliee ? LARGEUR_REPLIEE : LARGEUR_OUVERTE }}
          className="fixed inset-y-0 left-0 z-20 flex flex-col gap-4 overflow-y-auto overflow-x-hidden border-r border-navborder bg-navbg px-3 py-4 text-navfg transition-[width] duration-200"
        >
          <div className={`flex items-center gap-2.5 px-1.5 ${repliee ? 'justify-center' : ''}`}>
            {/* Logo de l'ANSD sur fond blanc : ses bleus se perdraient sur le bleu nuit de la barre. */}
            <div className="grid h-11 w-11 flex-none place-items-center overflow-hidden rounded-[10px] bg-white p-0.5">
              <img src={logoAnsd} alt="ANSD" className="h-full w-full object-contain" />
            </div>
            {!repliee && (
              <div className="min-w-0">
                <strong className="block truncate font-titre text-[17px] leading-tight text-navstrong">SISEP</strong>
                <span className="text-[10px] uppercase tracking-[.16em] text-navmuted">PHASAOC · ANSD</span>
              </div>
            )}
          </div>

          <button
            type="button"
            onClick={basculerRepli}
            aria-label={repliee ? 'Déplier la navigation' : 'Replier la navigation'}
            title={repliee ? 'Déplier la navigation' : 'Replier la navigation'}
            className="flex items-center justify-center gap-2 self-stretch rounded-[8px] border border-navborder py-1.5 text-navmuted transition-colors hover:bg-navhover hover:text-navstrong"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" className={`h-3.5 w-3.5 transition-transform duration-200 ${repliee ? 'rotate-180' : ''}`}>
              <path d="M15 5l-7 7 7 7" />
            </svg>
            {!repliee && <span className="text-[11px] uppercase tracking-[.1em]">Replier</span>}
          </button>

          <div className="flex flex-1 flex-col gap-0.5">
            {!repliee && <p className="px-2.5 pb-1 pt-2 text-[9.5px] uppercase tracking-[.18em] text-navmuted">Pilotage</p>}
            {liensPilotage.map((lien) => (
              <LienNavItem key={lien.to} lien={lien} actif={estActif(lien.to)} repliee={repliee} />
            ))}

            {!repliee && (
              <p className="px-2.5 pb-1 pt-3.5 text-[9.5px] uppercase tracking-[.18em] text-navmuted">Acteurs et paramètres</p>
            )}
            {repliee && <div className="mx-2 mt-2 border-t border-navborder" aria-hidden="true" />}
            {liensActeurs.map((lien) => (
              <LienNavItem key={lien.to} lien={lien} actif={estActif(lien.to)} repliee={repliee} />
            ))}
          </div>

          <div className="mt-auto border-t border-navborder pt-3">
            {utilisateur && (
              <MenuUtilisateur
                utilisateur={utilisateur}
                repliee={repliee}
                onDeconnecter={seDeconnecter}
                onModifierMotDePasse={() => setModaleMotDePasse(true)}
              />
            )}
            {!repliee && (
              <p className="mt-2 text-[11.5px] text-navmuted">
                <br />
              </p>
            )}
          </div>
        </nav>

        <div style={{ paddingLeft: repliee ? LARGEUR_REPLIEE : LARGEUR_OUVERTE }} className="transition-[padding-left] duration-200">
          <BandeauAnsd>
            <p className="font-titre text-base leading-tight font-bold sm:text-lg">Système de suivi-évaluation</p>
            <p className="hidden text-xs tracking-[.08em] text-white/85 uppercase sm:block">Projet PHASAOC</p>
          </BandeauAnsd>
          <main className="mx-auto w-full min-w-0 max-w-[1560px] px-7 py-6">
            <div key={location.pathname} className="entree-page">
              <Outlet />
            </div>
          </main>
        </div>
      </div>

      {modaleMotDePasse && <ModifierMotDePasseModale onFermer={() => setModaleMotDePasse(false)} />}
      </ConfirmationProvider>
    </ToastProvider>
  )
}

function LienNavItem({ lien, actif, repliee }: { lien: LienNav; actif: boolean; repliee: boolean }) {
  return (
    <Link
      to={lien.to}
      aria-current={actif ? 'page' : undefined}
      title={repliee ? lien.label : undefined}
      className={`flex items-center gap-2.5 rounded-[8px] border px-2.5 py-2.5 text-[13.5px] transition-colors ${repliee ? 'justify-center' : ''} ${
        actif
          ? 'border-transparent bg-accent font-semibold text-white'
          : 'border-transparent text-navfg hover:bg-navhover hover:text-navstrong'
      }`}
    >
      <span className="h-[18px] w-[18px] flex-none">{ICONES[lien.icone]}</span>
      {!repliee && (
        <>
          <span className="min-w-0 flex-1 truncate">{lien.label}</span>
          {lien.compte !== undefined && <span className="chiffres flex-none text-[11px] opacity-70">{lien.compte}</span>}
        </>
      )}
    </Link>
  )
}
