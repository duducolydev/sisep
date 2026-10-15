/** Natures de document — miroir de l'enum PHP App\Enum\TypeDocument. */
export const TYPES_DOCUMENT = [
  { valeur: 'rapport', libelle: 'Rapport' },
  { valeur: 'compte_rendu', libelle: 'Compte-rendu' },
  { valeur: 'manuel', libelle: 'Manuel / guide' },
  { valeur: 'proces_verbal', libelle: 'Procès-verbal' },
  { valeur: 'termes_reference', libelle: 'Termes de référence' },
  { valeur: 'note', libelle: 'Note technique' },
  { valeur: 'presentation', libelle: 'Présentation' },
  { valeur: 'contrat', libelle: 'Contrat / convention' },
  { valeur: 'facture', libelle: 'Facture / pièce comptable' },
  { valeur: 'liste_presence', libelle: 'Liste de présence' },
  { valeur: 'photo', libelle: 'Photo' },
  { valeur: 'correspondance', libelle: 'Correspondance' },
  { valeur: 'autre', libelle: 'Autre' },
] as const

/** Nature choisie au dépôt : un type, et une précision libre si « Autre ». */
export interface NatureDocument {
  type: string
  precision: string
}

export const NATURE_VIDE: NatureDocument = { type: '', precision: '' }

/** Message d'erreur à afficher, ou null si la nature est complète — mêmes règles que le serveur. */
export function erreurNature(nature: NatureDocument): string | null {
  if (nature.type === '') return 'Choisissez le type du document.'
  if (nature.type === 'autre' && nature.precision.trim() === '') return 'Précisez le type de document.'
  if (nature.precision.trim().length > 120) return 'La précision tient en 120 caractères au plus.'

  return null
}

/** Ajoute la nature à un envoi multipart (champs `typeDocument` et `typeDocumentPrecision`). */
export function ajouterNature(donnees: FormData, nature: NatureDocument): void {
  donnees.append('typeDocument', nature.type)
  if (nature.type === 'autre') donnees.append('typeDocumentPrecision', nature.precision.trim())
}
