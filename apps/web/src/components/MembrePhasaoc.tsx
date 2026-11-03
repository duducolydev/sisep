import type { ComponentProps } from 'react'

/** Case « membre de l'équipe PHASAOC » des modales de compte — prend directement le retour de `register()`. */
export function ChampMembrePhasaoc(props: Omit<ComponentProps<'input'>, 'type'>) {
  return (
    <div className="flex flex-col gap-1">
      <label className="flex items-center gap-2">
        <input type="checkbox" {...props} />
        Membre de l'équipe PHASAOC
      </label>
      <p className="text-xs text-muted">Pour reconnaître le personnel du projet dans le système. N'ouvre aucun droit : ceux-ci viennent des rôles.</p>
    </div>
  )
}

/** Repère affiché à côté du nom d'un membre de l'équipe du projet. */
export function PastilleMembrePhasaoc() {
  return (
    <span
      title="Membre de l'équipe PHASAOC"
      className="ml-2 inline-flex items-center rounded-full bg-accent-bg px-2 py-0.5 align-middle text-[10px] font-semibold tracking-[.08em] text-accent uppercase"
    >
      PHASAOC
    </span>
  )
}
