import { useState } from 'react'
import * as Popover from '@radix-ui/react-popover'
import { IconeRecherche, IconeValider } from './Icones'

interface Option {
  value: string
  label: string
}

interface ComboboxSimpleProps {
  options: Option[]
  valeur: string
  onChange: (valeur: string) => void
  placeholder: string
  disabled?: boolean
  id?: string
}

/** Pendant mono-sélection de ComboboxMultiple — même combobox cherchable façon select2, bâti sur le Popover Radix, sans bibliothèque supplémentaire. */
export function ComboboxSimple({ options, valeur, onChange, placeholder, disabled, id }: ComboboxSimpleProps) {
  const [ouvert, setOuvert] = useState(false)
  const [recherche, setRecherche] = useState('')

  const filtrees = options.filter((o) => o.label.toLowerCase().includes(recherche.toLowerCase()))
  const selectionnee = options.find((o) => o.value === valeur)

  function choisir(option: Option) {
    onChange(option.value)
    setRecherche('')
    setOuvert(false)
  }

  return (
    <Popover.Root
      open={ouvert}
      onOpenChange={(suivant) => {
        if (suivant) setRecherche('')
        setOuvert(suivant)
      }}
    >
      <Popover.Trigger asChild>
        <button
          type="button"
          id={id}
          disabled={disabled}
          className="flex w-full items-center justify-between gap-2 rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-left text-sm transition-colors hover:border-accent disabled:opacity-60"
        >
          <span className={selectionnee ? 'truncate' : 'truncate text-muted'}>{selectionnee?.label ?? placeholder}</span>
          <span className="shrink-0 text-xs text-muted">{ouvert ? '▲' : '▼'}</span>
        </button>
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          align="start"
          sideOffset={4}
          className="z-50 w-[var(--radix-popover-trigger-width)] rounded-[var(--radius-controle)] border border-border bg-surface shadow-[var(--shadow-carte)]"
        >
          <div className="flex items-center gap-2 border-b border-border px-3 py-2">
            <IconeRecherche />
            <input
              autoFocus
              type="text"
              value={recherche}
              onChange={(e) => setRecherche(e.target.value)}
              placeholder="Rechercher…"
              className="w-full text-sm outline-none"
            />
          </div>
          <div className="max-h-48 overflow-y-auto p-1">
            {filtrees.length === 0 ? (
              <p className="px-2 py-2 text-sm text-muted">Aucun résultat.</p>
            ) : (
              filtrees.map((o) => (
                <button
                  key={o.value}
                  type="button"
                  onClick={() => choisir(o)}
                  className={`flex w-full items-center justify-between gap-2 rounded-[var(--radius-controle)] px-2 py-1.5 text-left text-sm transition-colors hover:bg-nested ${
                    o.value === valeur ? 'font-semibold text-accent' : ''
                  }`}
                >
                  <span className="truncate">{o.label}</span>
                  {o.value === valeur && <IconeValider />}
                </button>
              ))
            )}
          </div>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  )
}
