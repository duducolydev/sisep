import * as DropdownMenu from '@radix-ui/react-dropdown-menu'
import { Link } from 'react-router'
import { Avatar } from './Avatar'
import type { ProfilUtilisateur } from '@/lib/auth'

interface MenuUtilisateurProps {
  utilisateur: ProfilUtilisateur
  repliee: boolean
  onDeconnecter: () => void
  onModifierMotDePasse: () => void
}

/** Ouvre vers le haut (side="top") : ancré en bas de la sidebar, pas de place en dessous. */
export function MenuUtilisateur({ utilisateur, repliee, onDeconnecter, onModifierMotDePasse }: MenuUtilisateurProps) {
  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger asChild>
        <button
          type="button"
          title={repliee ? `${utilisateur.prenom} ${utilisateur.nom}` : undefined}
          className={`flex items-center gap-2 rounded-[8px] py-1.5 text-left transition-colors hover:bg-navhover ${
            repliee ? 'w-full justify-center px-0' : 'w-full px-1.5'
          }`}
        >
          <Avatar prenom={utilisateur.prenom} nom={utilisateur.nom} taille="sm" />
          {!repliee && (
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[12.5px] font-semibold text-navfg">
                {utilisateur.prenom} {utilisateur.nom}
              </span>
              {utilisateur.fonction && <span className="block truncate text-[10.5px] text-navmuted">{utilisateur.fonction}</span>}
            </span>
          )}
        </button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Portal>
        <DropdownMenu.Content
          side="top"
          align="start"
          sideOffset={8}
          className="z-30 w-56 rounded-[var(--radius-carte)] border border-border bg-surface p-1.5 shadow-[var(--shadow-modal)]"
        >
          <DropdownMenu.Item asChild>
            <Link
              to="/profil"
              className="block cursor-pointer rounded-[var(--radius-controle)] px-3 py-2 text-sm text-text no-underline outline-none transition-colors hover:bg-hover focus:bg-hover"
            >
              Mon profil
            </Link>
          </DropdownMenu.Item>
          <DropdownMenu.Item
            onSelect={onModifierMotDePasse}
            className="cursor-pointer rounded-[var(--radius-controle)] px-3 py-2 text-sm text-text outline-none transition-colors hover:bg-hover focus:bg-hover"
          >
            Modifier le mot de passe
          </DropdownMenu.Item>
          <DropdownMenu.Separator className="my-1.5 h-px bg-border" />
          <DropdownMenu.Item
            onSelect={onDeconnecter}
            className="cursor-pointer rounded-[var(--radius-controle)] px-3 py-2 text-sm text-bad outline-none transition-colors hover:bg-bad-bg focus:bg-bad-bg"
          >
            Déconnexion
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  )
}
