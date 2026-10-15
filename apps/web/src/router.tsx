import { createBrowserRouter, Navigate, redirect } from 'react-router'
import { Layout } from './components/Layout'
import { Connexion } from './pages/Connexion'
import { ChangerMotDePasse } from './pages/ChangerMotDePasse'
import { profilQueryOptions } from './lib/auth'
import { queryClient } from './lib/query-client'
import { gardePermission, gardeAccesIndicateurs } from './lib/garde-permission'

// Routes § 8.1 du brief — l'état des filtres et des onglets vit dans l'URL,
// jamais dans un useState : un lien vers une vue filtrée doit être partageable.
// Chaque page est chargée à la demande (`lazy`, § 10 P7 — performance) : la
// coquille (Layout, connexion) reste dans le paquet initial, le reste se
// scinde par route au lieu d'un unique gros paquet.
export const router = createBrowserRouter([
  { path: '/connexion', element: <Connexion /> },
  {
    // Hors de la coquille : tant que le mot de passe temporaire n'est pas
    // changé, le serveur refuse toute donnée métier, la coquille n'aurait
    // rien à afficher. N'ouvre qu'à un compte connecté qui doit changer.
    path: '/changer-mot-de-passe',
    element: <ChangerMotDePasse />,
    loader: async () => {
      const profil = await queryClient.ensureQueryData(profilQueryOptions())
      if (profil === null) {
        throw redirect('/connexion')
      }
      if (!profil.doitChangerMdp) {
        throw redirect('/tableau-de-bord')
      }
      return null
    },
  },
  {
    path: '/',
    element: <Layout />,
    loader: async () => {
      const profil = await queryClient.ensureQueryData(profilQueryOptions())
      if (profil === null) {
        throw redirect('/connexion')
      }
      if (profil.doitChangerMdp) {
        throw redirect('/changer-mot-de-passe')
      }
      return null
    },
    children: [
      { index: true, element: <Navigate to="/tableau-de-bord" replace /> },
      { path: 'profil', lazy: () => import('./pages/Profil').then((m) => ({ Component: m.Profil })) },
      { path: 'tableau-de-bord', lazy: () => import('./pages/TableauDeBord').then((m) => ({ Component: m.TableauDeBord })) },
      { path: 'composantes', lazy: () => import('./pages/Composantes').then((m) => ({ Component: m.Composantes })) },
      {
        path: 'composantes/activites/:uuid',
        lazy: () => import('./pages/ActiviteDetail').then((m) => ({ Component: m.ActiviteDetail })),
      },
      { path: 'budget', lazy: () => import('./pages/Budget').then((m) => ({ Component: m.Budget })) },
      { path: 'execution', lazy: () => import('./pages/Execution').then((m) => ({ Component: m.Execution })) },
      {
        path: 'indicateurs',
        loader: gardeAccesIndicateurs,
        lazy: () => import('./pages/Indicateurs').then((m) => ({ Component: m.Indicateurs })),
      },
      {
        path: 'indicateurs/:uuid',
        loader: gardeAccesIndicateurs,
        lazy: () => import('./pages/IndicateurDetail').then((m) => ({ Component: m.IndicateurDetail })),
      },
      { path: 'initiatives', lazy: () => import('./pages/Initiatives').then((m) => ({ Component: m.Initiatives })) },
      // Pas de garde : la bibliothèque se lit par tous, chaque document
      // décidant lui-même de sa visibilité côté serveur.
      { path: 'documents', lazy: () => import('./pages/Documents').then((m) => ({ Component: m.Documents })) },
      {
        path: 'initiatives/:uuid',
        lazy: () => import('./pages/InitiativeDetail').then((m) => ({ Component: m.InitiativeDetail })),
      },
      {
        path: 'ressources-humaines',
        loader: gardePermission('rh.consulter'),
        lazy: () => import('./pages/RessourcesHumaines').then((m) => ({ Component: m.RessourcesHumaines })),
      },
      {
        path: 'parties-prenantes',
        lazy: () => import('./pages/PartiesPrenantes').then((m) => ({ Component: m.PartiesPrenantes })),
      },
      {
        path: 'parties-prenantes/:uuid',
        lazy: () => import('./pages/PartiePrenanteDetail').then((m) => ({ Component: m.PartiePrenanteDetail })),
      },
      {
        path: 'administration/indicateurs',
        loader: gardePermission('indicateurs.definir'),
        lazy: () => import('./pages/administration/AdminIndicateurs').then((m) => ({ Component: m.AdminIndicateurs })),
      },
      {
        path: 'administration/gabarits-etapes',
        loader: gardePermission('admin.parametres'),
        lazy: () =>
          import('./pages/administration/AdminGabaritsEtapes').then((m) => ({ Component: m.AdminGabaritsEtapes })),
      },
      {
        path: 'administration/parties-prenantes',
        lazy: () =>
          import('./pages/administration/AdminPartiesPrenantes').then((m) => ({ Component: m.AdminPartiesPrenantes })),
      },
      {
        path: 'administration/comptes',
        loader: gardePermission('admin.utilisateurs'),
        lazy: () => import('./pages/administration/AdminUtilisateurs').then((m) => ({ Component: m.AdminUtilisateurs })),
      },
      {
        path: 'administration/roles',
        loader: gardePermission('admin.roles'),
        lazy: () => import('./pages/administration/AdminRoles').then((m) => ({ Component: m.AdminRoles })),
      },
      {
        path: 'administration/journal',
        loader: gardePermission('journal.consulter'),
        lazy: () => import('./pages/administration/AdminJournal').then((m) => ({ Component: m.AdminJournal })),
      },
      {
        path: 'administration/parametres',
        loader: gardePermission('admin.parametres'),
        lazy: () => import('./pages/administration/AdminParametres').then((m) => ({ Component: m.AdminParametres })),
      },
    ],
  },
])
