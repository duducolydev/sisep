import { NavLink } from 'react-router'
import { useUtilisateurCourant } from '@/lib/use-permissions'
import { ongletsAdministration } from '@/lib/admin'

/** Sous-onglets internes de la page Administration — § 2.6, § 9.4 (`SousOnglets`). */
export function AdminOnglets() {
  const { data: utilisateur } = useUtilisateurCourant()
  const onglets = ongletsAdministration(utilisateur)

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
