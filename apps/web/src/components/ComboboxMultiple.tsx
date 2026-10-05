import { useState } from 'react'
import * as Popover from '@radix-ui/react-popover'
import { IconeRecherche } from './Icones'

interface Option {
  value: string
  label: string
}

interface ComboboxMultipleProps {
  options: Option[]
  selectionnes: string[]
  onChange: (selectionnes: string[]) => void
  placeholder: string
}

/** Combobox cherchable multi-sélection façon select2 — bâti sur le Popover Radix déjà utilisé ailleurs, pas de bibliothèque supplémentaire. */
export function ComboboxMultiple({ options, selectionnes, onChange, placeholder }: ComboboxMultipleProps) {
  const [ouvert, setOuvert] = useState(false)
  const [recherche, setRecherche] = useState('')

  const filtrees = options.filter((o) => o.label.toLowerCase().includes(recherche.toLowerCase()))
  const libellesSelectionnes = options.filter((o) => selectionnes.includes(o.value)).map((o) => o.label)

  function basculer(valeur: string) {
    onChange(selectionnes.includes(valeur) ? selectionnes.filter((v) => v !== valeur) : [...selectionnes, valeur])
  }

  return (
    <Popover.Root open={ouvert} onOpenChange={setOuvert}>
      <Popover.Trigger asChild>
        <button
          type="button"
          className="flex w-full items-center justify-between gap-2 rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-left text-sm"
        >
          <span className={libellesSelectionnes.length > 0 ? 'truncate' : 'truncate text-muted'}>
            {libellesSelectionnes.length > 0 ? libellesSelectionnes.join(', ') : placeholder}
          </span>
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
                <label
                  key={o.value}
                  className="flex cursor-pointer items-center gap-2 rounded-[var(--radius-controle)] px-2 py-1.5 text-sm hover:bg-nested"
                >
                  <input type="checkbox" checked={selectionnes.includes(o.value)} onChange={() => basculer(o.value)} />
                  {o.label}
                </label>
              ))
            )}
          </div>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  )
}
