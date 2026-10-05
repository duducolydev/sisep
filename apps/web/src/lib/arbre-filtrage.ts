import type { NoeudActivite, NoeudComposante } from './composantes'

export interface FiltresArbre {
  composante: string | null
  statut: string | null
  structure: string | null
  recherche: string | null
}

export const FILTRES_VIDES: FiltresArbre = { composante: null, statut: null, structure: null, recherche: null }

function activiteCorrespond(a: NoeudActivite, filtres: FiltresArbre, recherche: string | null): boolean {
  if (filtres.statut && a.statut !== filtres.statut) return false
  if (filtres.structure && a.structureResponsable.uuid !== filtres.structure) return false
  if (recherche && !`${a.code} ${a.libelle}`.toLowerCase().includes(recherche)) return false
  return true
}

/**
 * Filtrage entièrement côté client sur l'arbre déjà chargé (§ 6.2 du brief :
 * "l'arbre complet... pour le filtrage instantané" — pas de rappel serveur
 * à chaque changement de filtre).
 */
export function filtrerArbre(composantes: NoeudComposante[], filtres: FiltresArbre): NoeudComposante[] {
  const recherche = filtres.recherche?.trim().toLowerCase() || null
  const filtreActiviteActif = Boolean(filtres.statut || filtres.structure || recherche)

  return composantes
    .filter((c) => !filtres.composante || c.uuid === filtres.composante)
    .map((c) => ({
      ...c,
      sousComposantes: c.sousComposantes?.map((sc) => ({
        ...sc,
        activites: filtreActiviteActif
          ? (sc.activites ?? []).filter((a) => activiteCorrespond(a, filtres, recherche))
          : sc.activites,
      })),
    }))
    .filter((c) => {
      if (!filtreActiviteActif) return true

      return (c.sousComposantes ?? []).some((sc) => (sc.activites ?? []).length > 0)
    })
    .map((c) => ({
      ...c,
      sousComposantes: filtreActiviteActif
        ? c.sousComposantes?.filter((sc) => (sc.activites ?? []).length > 0)
        : c.sousComposantes,
    }))
}

export function compterActivites(composantes: NoeudComposante[]): number {
  return composantes.reduce(
    (total, c) =>
      total + (c.sousComposantes ?? []).reduce((sousTotal, sc) => sousTotal + (sc.activites?.length ?? 0), 0),
    0,
  )
}
