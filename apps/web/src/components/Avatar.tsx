interface AvatarProps {
  prenom: string
  nom: string
  taille?: 'sm' | 'md'
}

function initiales(prenom: string, nom: string): string {
  return `${prenom.charAt(0)}${nom.charAt(0)}`.toUpperCase()
}

/** Avatar par initiales — pas de photo de profil dans ce système, juste prénom+nom (§ ProfilUtilisateur). */
export function Avatar({ prenom, nom, taille = 'md' }: AvatarProps) {
  const classeTaille = 'sm' === taille ? 'h-8 w-8 text-xs' : 'h-9 w-9 text-sm'

  return (
    <div
      className={`grid flex-none place-items-center rounded-full bg-accent-clair font-titre font-bold text-white ${classeTaille}`}
      aria-hidden="true"
    >
      {initiales(prenom, nom)}
    </div>
  )
}
