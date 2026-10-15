const BASE = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'

export type TypeImport = 'personnel' | 'effectifs-enqueteurs' | 'points-focaux'

/** Miroir de LecteurFichierImport::TAILLE_MAX_OCTETS — vérifié avant l'envoi. */
export const TAILLE_MAX_IMPORT_OCTETS = 5 * 1024 * 1024
export const EXTENSIONS_IMPORT = ['.xlsx', '.xls', '.csv']

export type StatutLigneImport = 'creation' | 'mise_a_jour' | 'existante' | 'doublon' | 'erreur'

export interface RapportImport {
  type: TypeImport
  applique: boolean
  fichier: { nom: string; tailleOctets: number; format: string; encodage: string | null; colonnesIgnorees: string[] }
  resume: { lignes: number; creations: number; misesAJour: number; existantes: number; doublons: number; erreurs: number }
  lignes: { ligne: number; libelle: string; statut: StatutLigneImport; messages: string[] }[]
}

export function urlModeleImport(type: TypeImport): string {
  return `${BASE}/imports/${type}/modele.xlsx`
}

/** Aperçu (`confirmer` faux) ou import effectif du fichier — même fichier, deux appels. */
export async function envoyerImport(
  type: TypeImport,
  fichier: File,
  options: { miseAJour: boolean; confirmer: boolean },
): Promise<RapportImport> {
  const donnees = new FormData()
  donnees.append('fichier', fichier)
  donnees.append('miseAJour', options.miseAJour ? '1' : '0')
  donnees.append('confirmer', options.confirmer ? '1' : '0')

  const reponse = await fetch(`${BASE}/imports/${type}`, { method: 'POST', credentials: 'include', body: donnees })
  if (reponse.status === 413) {
    throw new Error('Fichier trop volumineux : 5 Mo au plus.')
  }
  if (!reponse.ok) {
    const probleme = (await reponse.json().catch(() => undefined)) as { detail?: string; title?: string } | undefined
    throw new Error(probleme?.detail ?? probleme?.title ?? `Erreur ${reponse.status}`)
  }

  return reponse.json() as Promise<RapportImport>
}
