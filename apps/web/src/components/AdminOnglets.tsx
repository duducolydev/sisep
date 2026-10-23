import { NavLink } from 'react-router'
import { useUtilisateurCourant } from '@/lib/use-permissions'

/**
 * Chaque onglet porte la permission qui ouvre réellement sa page. Sans cela,
 * un responsable suivi-évaluation voyait « Comptes utilisateurs » et « Rôles
 * et permissions », cliquait, et tombait sur un 403 : le serveur refusait à
 * juste titre, mais l'interface n'aurait jamais dû proposer le chemin.
 *
 * `null` = onglet ouvert à tout utilisateur authentifié (la liste des
 * structures est lisible par tous ; seule sa modification est gardée, dans
 * la page elle-même).
 */
const ONGLETS: { to: string; label: string; permission: string | null }[] = [
  { to: '/administration/indicateurs', label: 'Référentiel des indicateurs de performance', permission: 'indicateurs.definir' },
  { to: '/administration/modeles-etapes', label: "Modèles d'étapes", permission: 'admin.parametres' },
  { to: '/administration/parties-prenantes', label: 'Parties prenantes', permission: null },
  { to: '/administration/comptes', label: 'Comptes utilisateurs', permission: 'admin.utilisateurs' },
  { to: '/administration/roles', label: 'Rôles et permissions', permission: 'admin.roles' },
  { to: '/administration/journal', label: "Journal d'activité", permission: 'journal.consulter' },
  { to: '/administration/parametres', label: 'Paramètres', permission: 'admin.parametres' },
]

/** Sous-onglets internes de la page Administration — § 2.6, § 9.4 (`SousOnglets`). */
export function AdminOnglets() {
  const { data: utilisateur } = useUtilisateurCourant()
  const permissions = utilisateur?.permissions ?? []
  const onglets = ONGLETS.filter((o) => o.permission === null || permissions.includes(o.permission))

  return (
    <nav aria-label="Sections de l'administration" className="mb-6 flex flex-wrap gap-1 border-b border-border">
      {onglets.map((onglet) => (
        <NavLink
          key={onglet.to}
          to={onglet.to}
          className={({ isActive }) =>
            `border-b-2 px-3.5 py-2.5 text-sm transition-colors ${
              isActive ? 'border-accent font-semibold text-accent' : 'border-transparent text-muted hover:text-text'
            }`
          }
        >
          {onglet.label}
        </NavLink>
      ))}
    </nav>
  )
}
