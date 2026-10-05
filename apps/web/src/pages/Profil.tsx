import { useState } from 'react'
import { useUtilisateurCourant } from '@/lib/use-permissions'
import { Avatar } from '@/components/Avatar'
import { ModifierMotDePasseModale } from '@/components/ModifierMotDePasseModale'
import { Squelette } from '@/components/Squelette'

export function Profil() {
  const { data: utilisateur, isLoading } = useUtilisateurCourant()
  const [modaleMotDePasse, setModaleMotDePasse] = useState(false)

  if (isLoading) return <Squelette lignes={5} />
  if (!utilisateur) return null

  return (
    <div className="flex max-w-2xl flex-col gap-6">
      <div>
        <p className="font-titre text-eyebrow uppercase tracking-wide">Compte</p>
        <h1 className="text-2xl">Mon profil</h1>
      </div>

      <div className="flex items-center gap-4 rounded-[var(--radius-carte)] bg-surface p-5 shadow-[var(--shadow-carte)]">
        <Avatar prenom={utilisateur.prenom} nom={utilisateur.nom} />
        <div className="min-w-0">
          <p className="truncate text-lg font-semibold">
            {utilisateur.prenom} {utilisateur.nom}
          </p>
          {utilisateur.fonction && <p className="truncate text-sm text-muted">{utilisateur.fonction}</p>}
        </div>
      </div>

      <div className="flex flex-col gap-4 rounded-[var(--radius-carte)] bg-surface p-5 shadow-[var(--shadow-carte)]">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted">Informations</h2>
        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-muted">Email</dt>
            <dd className="truncate">{utilisateur.email}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted">Structure</dt>
            <dd className="truncate">{utilisateur.structure ?? '—'}</dd>
          </div>
        </dl>
      </div>

      <div className="flex flex-col gap-4 rounded-[var(--radius-carte)] bg-surface p-5 shadow-[var(--shadow-carte)]">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted">Rôles</h2>
        {utilisateur.roles.length === 0 ? (
          <p className="text-sm text-muted">Aucun rôle attribué.</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {utilisateur.roles.map((r, i) => (
              <li key={`${r.code}-${i}`} className="flex items-center justify-between text-sm">
                <span>{r.libelle}</span>
                <span className="text-xs text-muted">{r.structure ?? 'Portée globale'}</span>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="flex flex-col gap-3 rounded-[var(--radius-carte)] bg-surface p-5 shadow-[var(--shadow-carte)]">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted">Sécurité</h2>
        <div className="flex items-center justify-between gap-4">
          <p className="text-sm text-muted">Mot de passe</p>
          <button
            type="button"
            onClick={() => setModaleMotDePasse(true)}
            className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-1.5 text-sm font-semibold transition-colors hover:border-accent hover:text-accent"
          >
            Modifier
          </button>
        </div>
      </div>

      {modaleMotDePasse && <ModifierMotDePasseModale onFermer={() => setModaleMotDePasse(false)} />}
    </div>
  )
}
