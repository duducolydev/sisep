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

export interface LigneTauxExecution {
  libelle: string
  /** Part exécutée en % (une décimale), null si aucune dotation notifiée. */
  execution: number | null
  /** Part restant à dépenser en % : notifié moins dépensé. Ce n'est pas le disponible (notifié moins engagé). */
  resteADepenser: number | null
  /** Sommet de la barre, où se pose l'étiquette du taux. */
  sommet: number | null
  etiquette: string | null
}

/**
 * Barres empilées à 100 % du taux d'exécution.
 *
 * L'étiquette dit toujours le taux exécuté, et le dit en toutes lettres : la
 * barre étant pleine quel que soit l'avancement, un « 100 % » posé sur la part
 * restante se lisait comme un budget entièrement exécuté alors que rien
 * n'était dépensé. Un taux indéfini (division par zéro) ne porte aucune barre
 * ni étiquette, jamais un faux 0 %.
 */
export function lignesTauxExecution(donnees: { libelle: string; tauxExecution: number | null }[]): LigneTauxExecution[] {
  return donnees.map(({ libelle, tauxExecution }) => {
    if (tauxExecution === null) {
      return { libelle, execution: null, resteADepenser: null, sommet: null, etiquette: null }
    }

    const execution = Math.round(tauxExecution * 1000) / 10

    return {
      libelle,
      execution,
      resteADepenser: Math.round((100 - execution) * 10) / 10,
      sommet: 100,
      etiquette: `${execution.toLocaleString('fr-FR')} % exécuté`,
    }
  })
}
