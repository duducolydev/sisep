import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'
import { ajouterNature, type NatureDocument } from './types-document'

/** Niveau de rattachement d'un document — segment d'URL de l'API. */
export type TypeRattachement = 'projets' | 'composantes' | 'sous-composantes'

/**
 * Visibilités proposées par la bibliothèque. « Agents spécifiques » existe
 * dans le modèle mais pas ici : choisir nommément des agents relève de
 * l'écran des projets, et un document déposé sans agent choisi ne serait
 * visible de personne.
 */
export const VISIBILITES = [
  { valeur: 'tous', libelle: 'Tous les comptes' },
  { valeur: 'equipe_phasaoc', libelle: 'Équipe PHASAOC' },
  { valeur: 'sectoriels', libelle: 'Points focaux sectoriels' },
] as const

export const LIBELLES_VISIBILITE: Record<string, string> = Object.fromEntries(
  VISIBILITES.map((v) => [v.valeur, v.libelle]),
)

/** Déduite du type MIME côté serveur, jamais stockée. */
export type FamilleDocument = 'pdf' | 'texte' | 'tableur' | 'presentation' | 'image' | 'autre'

export const FAMILLES: { valeur: FamilleDocument; libelle: string }[] = [
  { valeur: 'pdf', libelle: 'PDF' },
  { valeur: 'texte', libelle: 'Document texte' },
  { valeur: 'tableur', libelle: 'Tableur' },
  { valeur: 'presentation', libelle: 'Présentation' },
  { valeur: 'image', libelle: 'Image' },
  { valeur: 'autre', libelle: 'Autre' },
]

export const LIBELLES_FAMILLE: Record<string, string> = Object.fromEntries(
  FAMILLES.map((f) => [f.valeur, f.libelle]),
)

/**
 * Ce que toute pièce jointe expose pour être requalifiée (intitulé, type) —
 * commun à la bibliothèque et aux justificatifs d'étape, de valeur
 * d'indicateur et de projet.
 */
export interface PieceRequalifiable {
  uuid: string
  /** Intitulé donné au dépôt ou ajouté après coup ; `null` : le nom de fichier en tient lieu. */
  libelle: string | null
  nomFichier: string
  /** Nature choisie au dépôt — null pour les dépôts antérieurs. */
  typeDocument: string | null
  typeDocumentPrecision: string | null
  /** « Rapport », « Autre : … » — prêt à afficher. */
  typeDocumentLibelle: string | null
  deposeParUuid: string
}

export interface Document extends PieceRequalifiable {
  mime: string
  famille: FamilleDocument
  tailleOctets: number
  visibilite: string
  deposePar: string
  creeLe: string
}

export interface FiltresDocuments {
  /** Recherche libre sur l'intitulé et le nom de fichier. */
  q?: string
  /** Bornes de la date de dépôt, incluses toutes les deux. */
  du?: string
  au?: string
  famille?: string
  /** Nature du document (rapport, compte-rendu…). */
  type?: string
}

export const documentsQueryOptions = (
  type: TypeRattachement,
  uuid: string | undefined,
  filtres: FiltresDocuments = {},
) =>
  queryOptions({
    queryKey: ['documents', type, uuid, filtres],
    queryFn: () => {
      const params = new URLSearchParams()
      for (const [cle, valeur] of Object.entries(filtres)) {
        if (valeur !== undefined && valeur !== '') {
          params.set(cle, valeur)
        }
      }
      const requete = params.toString()

      return apiClient.get<{ donnees: Document[] }>(`/documents/${type}/${uuid}${requete === '' ? '' : `?${requete}`}`)
    },
    enabled: uuid !== undefined,
  })

/** fetch brut multipart — apiClient ne gère pas FormData, même motif que les autres téléversements. */
/** Miroir de DocumentController::TAILLE_MAX_OCTETS — vérifié avant l'envoi pour ne pas téléverser 30 Mo en vain. */
export const TAILLE_MAX_DOCUMENT_OCTETS = 50 * 1024 * 1024

export async function deposerDocument(
  type: TypeRattachement,
  uuid: string,
  entree: { fichier: File; libelle: string; visibilite: string; nature: NatureDocument },
): Promise<Document> {
  const donnees = new FormData()
  donnees.append('fichier', entree.fichier)
  donnees.append('libelle', entree.libelle)
  donnees.append('visibilite', entree.visibilite)
  ajouterNature(donnees, entree.nature)

  const reponse = await fetch(
    `${(import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'}/documents/${type}/${uuid}`,
    { method: 'POST', credentials: 'include', body: donnees },
  )
  if (reponse.status === 413) {
    // Refus du serveur web, avant l'application : pas de corps JSON à lire.
    throw new Error('Fichier trop volumineux : la taille maximale est de 50 Mo.')
  }
  if (!reponse.ok) {
    const probleme = (await reponse.json().catch(() => undefined)) as { detail?: string; title?: string } | undefined
    throw new Error(probleme?.detail ?? probleme?.title ?? `Erreur ${reponse.status}`)
  }

  return reponse.json() as Promise<Document>
}

export function retirerDocument(uuid: string) {
  return apiClient.delete<void>(`/documents/${uuid}`)
}

/** Corriger l'intitulé ou le type d'une pièce jointe — le fichier ne change pas. */
export function requalifierPieceJointe(uuid: string, entree: { libelle: string; nature: NatureDocument }) {
  return apiClient.patch<PieceRequalifiable>(`/pieces-jointes/${uuid}`, {
    libelle: entree.libelle.trim() === '' ? null : entree.libelle.trim(),
    typeDocument: entree.nature.type,
    typeDocumentPrecision: entree.nature.type === 'autre' ? entree.nature.precision.trim() : null,
  })
}

export function urlTelechargerDocument(uuid: string): string {
  return `${(import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'}/pieces-jointes/${uuid}/telecharger`
}

/** Affichage seulement — les tailles restent des octets exacts côté serveur. */
export function formaterTaille(octets: number): string {
  if (octets < 1024) return `${octets} o`
  if (octets < 1024 * 1024) return `${Math.round(octets / 1024)} Ko`

  return `${(octets / (1024 * 1024)).toFixed(1).replace('.', ',')} Mo`
}
