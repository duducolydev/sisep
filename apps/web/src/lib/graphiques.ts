/**
 * Hauteur d'un graphique en barres horizontales, une barre par ligne.
 *
 * Le cadre doit réserver la place que le graphique occupera réellement,
 * axe compris. Les graphiques de suivi calculaient une hauteur
 * proportionnelle au nombre de lignes — environ 50 px pour une seule — tout
 * en imposant au graphique un minimum de 160 px. Avec peu de lignes, il
 * débordait de son cadre : ses graduations tombaient sur la légende et sur
 * la carte du dessous. C'est le cas typique d'une base qui démarre.
 *
 * Le plancher est donc appliqué ici, au cadre, et nulle part ailleurs.
 */
export const HAUTEUR_MINIMALE_GRAPHIQUE = 140

/** Place de l'axe des valeurs et des marges hautes et basses. */
const HAUTEUR_AXE = 48

export function hauteurGraphiqueEnBarres(nombreDeLignes: number, hauteurParLigne: number): number {
  return Math.max(HAUTEUR_MINIMALE_GRAPHIQUE, nombreDeLignes * hauteurParLigne + HAUTEUR_AXE)
}
