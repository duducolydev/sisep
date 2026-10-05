const STATUTS: Record<string, { libelle: string; classe: string }> = {
  planifiee: { libelle: 'Planifiée', classe: 'text-muted bg-nested' },
  en_cours: { libelle: 'En cours', classe: 'text-accent bg-accent-bg' },
  terminee: { libelle: 'Terminée', classe: 'text-ok bg-ok-bg' },
  suspendue: { libelle: 'Suspendue', classe: 'text-warn bg-warn-bg' },
  annulee: { libelle: 'Annulée', classe: 'text-bad bg-bad-bg' },
  // Statut calculé du module Projets (jamais saisi) — fusionne statut + vigilance en une seule valeur.
  en_retard: { libelle: 'En retard', classe: 'text-warn bg-warn-bg' },
  a_risque: { libelle: 'À risque', classe: 'text-bad bg-bad-bg' },
  // Cycle de vie saisie/validation des engagements, dépenses et valeurs
  // d'indicateur (§ 4.3, § 4.4) — même composant, statuts distincts.
  brouillon: { libelle: 'Brouillon', classe: 'text-muted bg-nested' },
  valide: { libelle: 'Validé', classe: 'text-ok bg-ok-bg' },
  annule: { libelle: 'Annulé', classe: 'text-bad bg-bad-bg' },
}

/** Statut coloré avec point — § 9.4 du brief. */
export function Pastille({ statut }: { statut: string }) {
  const info = STATUTS[statut] ?? { libelle: statut, classe: 'text-muted bg-nested' }

  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${info.classe}`}>
      <span className="h-1.5 w-1.5 rounded-full bg-current" />
      {info.libelle}
    </span>
  )
}
