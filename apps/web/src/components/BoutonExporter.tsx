import { IconeExporter } from './Icones'

interface BoutonExporterProps {
  href: string
  libelle: string
}

/** Bouton d'export Excel/PDF — icône + texte + fond coloré (§ décision produit), jamais une icône nue. */
export function BoutonExporter({ href, libelle }: BoutonExporterProps) {
  return (
    <a
      href={href}
      className="flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-accent-d"
    >
      <IconeExporter />
      {libelle}
    </a>
  )
}
