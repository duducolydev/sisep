import { LIBELLES_STATUT_INITIATIVE, type StatutInitiativeActivite } from '@/lib/initiatives'

/**
 * Pastille dédiée au statut calculé du module Projets — distincte du
 * composant Pastille partagé : les valeurs 'planifiee'/'en_cours'/'terminee'
 * y sont aussi utilisées par le PTBA (App\Enum\StatutActivite, non
 * calculé), donc une seule couleur par clé ne peut pas y distinguer les
 * deux modules. Palette validée (scripts/validate_palette.js, skill
 * dataviz) — cf. tokens.css pour le détail des ajustements.
 */
const CLASSES_STATUT: Record<StatutInitiativeActivite, string> = {
  planifiee: 'text-statut-planifiee bg-statut-planifiee-bg',
  en_cours: 'text-accent bg-accent-bg',
  a_risque: 'text-bad bg-bad-bg',
  en_retard: 'text-eyebrow bg-eyebrow-bg',
  terminee: 'text-success bg-success-bg',
}

export function PastilleStatutInitiative({ statut }: { statut: StatutInitiativeActivite }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${CLASSES_STATUT[statut]}`}>
      <span className="h-1.5 w-1.5 rounded-full bg-current" />
      {LIBELLES_STATUT_INITIATIVE[statut]}
    </span>
  )
}
