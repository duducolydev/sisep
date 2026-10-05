interface PaginationProps {
  page: number
  totalPages: number
  total: number
  taille: number
  onChangerPage: (page: number) => void
}

/** Pagination générique — le filtrage/tri reste toujours fait avant, ce composant ne fait que trancher la page courante. */
export function Pagination({ page, totalPages, total, taille, onChangerPage }: PaginationProps) {
  if (totalPages <= 1) return null

  const debut = (page - 1) * taille + 1
  const fin = Math.min(page * taille, total)

  return (
    <nav aria-label="Pagination" className="flex items-center justify-between gap-4 border-t border-border px-4 py-3 text-sm">
      <p className="chiffres text-muted">
        {debut}–{fin} sur {total}
      </p>
      <div className="flex items-center gap-1">
        <button
          type="button"
          onClick={() => onChangerPage(page - 1)}
          disabled={page <= 1}
          className="rounded-[var(--radius-controle)] border border-border px-3 py-1.5 transition-colors hover:border-accent hover:text-accent disabled:opacity-40"
        >
          Précédent
        </button>
        <span className="chiffres px-2 text-muted">
          {page} / {totalPages}
        </span>
        <button
          type="button"
          onClick={() => onChangerPage(page + 1)}
          disabled={page >= totalPages}
          className="rounded-[var(--radius-controle)] border border-border px-3 py-1.5 transition-colors hover:border-accent hover:text-accent disabled:opacity-40"
        >
          Suivant
        </button>
      </div>
    </nav>
  )
}
