import { ComboboxSimple } from './ComboboxSimple'
import { TYPES_DOCUMENT, type NatureDocument } from '@/lib/types-document'

interface ChoixTypeDocumentProps {
  nature: NatureDocument
  onChange: (nature: NatureDocument) => void
  disabled?: boolean
  /** Préfixe des identifiants, pour plusieurs sélecteurs sur une même page. */
  id?: string
}

/**
 * Type d'un document au moment du dépôt (rapport, compte-rendu, manuel…) :
 * liste cherchable façon select2, et une zone de texte quand « Autre » est
 * choisi.
 */
export function ChoixTypeDocument({ nature, onChange, disabled, id = 'type-document' }: ChoixTypeDocumentProps) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id}>Type de document</label>
      <ComboboxSimple
        id={id}
        options={TYPES_DOCUMENT.map((t) => ({ value: t.valeur, label: t.libelle }))}
        valeur={nature.type}
        onChange={(type) => onChange({ type, precision: type === 'autre' ? nature.precision : '' })}
        placeholder="Choisir un type…"
        disabled={disabled}
      />
      {nature.type === 'autre' && (
        <input
          id={`${id}-precision`}
          aria-label="Précisez le type de document"
          value={nature.precision}
          maxLength={120}
          disabled={disabled}
          onChange={(e) => onChange({ ...nature, precision: e.target.value })}
          placeholder="Précisez : fiche de collecte, attestation…"
          className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
        />
      )}
    </div>
  )
}
