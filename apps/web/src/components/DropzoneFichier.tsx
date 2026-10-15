import { useRef, useState, type DragEvent } from 'react'
import { IconeTrombone } from './Icones'

interface DropzoneFichierProps {
  fichier: File | null
  onChange: (fichier: File | null) => void
  /** Extensions proposées par le sélecteur de fichiers (ex. ".xlsx,.csv") — le serveur reste seul juge. */
  accept?: string
}

function formaterTaille(octets: number): string {
  if (octets < 1024) return `${octets} o`
  if (octets < 1024 * 1024) return `${Math.round(octets / 1024)} Ko`
  return `${(octets / (1024 * 1024)).toFixed(1)} Mo`
}

/** Zone de dépôt glisser-déposer + repli clic-pour-parcourir — HTML5 natif, pas de bibliothèque. */
export function DropzoneFichier({ fichier, onChange, accept }: DropzoneFichierProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [survole, setSurvole] = useState(false)

  function surDepot(e: DragEvent<HTMLDivElement>) {
    e.preventDefault()
    setSurvole(false)
    const depose = e.dataTransfer.files[0]
    if (depose) onChange(depose)
  }

  return (
    <div
      role="button"
      tabIndex={0}
      onClick={() => inputRef.current?.click()}
      onKeyDown={(e) => 'Enter' === e.key && inputRef.current?.click()}
      onDragOver={(e) => {
        e.preventDefault()
        setSurvole(true)
      }}
      onDragLeave={() => setSurvole(false)}
      onDrop={surDepot}
      className={`flex cursor-pointer flex-col items-center gap-1.5 rounded-[var(--radius-controle)] border-2 border-dashed px-4 py-6 text-center transition-colors ${
        survole ? 'border-accent bg-accent-bg' : 'border-border bg-nested hover:border-accent'
      }`}
    >
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        className="hidden"
        onChange={(e) => onChange(e.target.files?.[0] ?? null)}
      />
      <IconeTrombone />
      {fichier ? (
        <p className="text-sm">
          <span className="font-semibold">{fichier.name}</span>{' '}
          <span className="chiffres text-muted">({formaterTaille(fichier.size)})</span>
        </p>
      ) : (
        <p className="text-sm text-muted">
          Glissez-déposez un fichier ici, ou <span className="font-semibold text-accent">cliquez pour parcourir</span>
        </p>
      )}
    </div>
  )
}
