import * as SwitchPrimitive from '@radix-ui/react-switch'

interface BasculeProps {
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  disabled?: boolean
  libelle: string
}

/** Interrupteur à bascule (Radix Switch, déjà une dépendance) — remplace une case à cocher partout où l'action bascule un état binaire (ex. tâche terminée/pas terminée). */
export function Bascule({ checked, onCheckedChange, disabled, libelle }: BasculeProps) {
  return (
    <label className="flex shrink-0 items-center gap-1.5 text-xs text-muted">
      <SwitchPrimitive.Root
        checked={checked}
        onCheckedChange={onCheckedChange}
        disabled={disabled}
        className="relative h-4 w-7 shrink-0 rounded-full bg-track outline-none transition-colors data-[state=checked]:bg-success disabled:opacity-60"
      >
        <SwitchPrimitive.Thumb className="block h-3 w-3 translate-x-0.5 rounded-full bg-white shadow transition-transform data-[state=checked]:translate-x-[14px]" />
      </SwitchPrimitive.Root>
      {libelle}
    </label>
  )
}
