const formateurFcfa = new Intl.NumberFormat('fr-FR', {
  maximumFractionDigits: 0,
})

const formateurPourcentage = new Intl.NumberFormat('fr-FR', {
  style: 'percent',
  maximumFractionDigits: 1,
})

const formateurDate = new Intl.DateTimeFormat('fr-FR', {
  timeZone: 'Africa/Dakar',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})

const formateurMds = new Intl.NumberFormat('fr-FR', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

// Décimales seulement quand la valeur en porte : un dénombrement s'affiche
// « 19 550 téléchargements », pas « 19 550,0 ».
const formateurValeurIndicateur = new Intl.NumberFormat('fr-FR', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
})

/** Formatage à l'affichage uniquement — les montants restent des entiers FCFA en base (§ 3.3). */
export function formaterFcfa(montant: number): string {
  return `${formateurFcfa.format(montant)} FCFA`
}

/** Division par zéro déjà résolue côté API en `null` — ici on affiche le tiret (§ 5.1). */
export function formaterTaux(taux: number | null): string {
  return taux === null ? '—' : formateurPourcentage.format(taux)
}

export function formaterDate(date: string | Date): string {
  return formateurDate.format(typeof date === 'string' ? new Date(date) : date)
}

/** Cartes du tableau de bord uniquement — le stockage reste en FCFA entiers (§ 3.3). */
export function formaterMds(montant: number): string {
  return formateurMds.format(montant / 1_000_000_000)
}

/** Affichage uniquement — l'API renvoie les valeurs d'indicateur en DECIMAL(…,4) brut, jamais arrondi en base. */
export function formaterValeurIndicateur(valeur: string | number): string {
  return formateurValeurIndicateur.format(Number(valeur))
}
