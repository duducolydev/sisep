import { useSearchParams } from 'react-router'

export type GranularitePeriode = 'aucun' | 'trimestre' | 'mois' | 'jour'

export function trimestreDe(mois: number): number {
  return Math.ceil(mois / 3)
}

/** Valeur par défaut (aujourd'hui) pour une granularité, au format attendu par son contrôle de saisie. */
export function valeurParDefaut(granularite: GranularitePeriode): string {
  const maintenant = new Date()
  const annee = maintenant.getFullYear()
  const mois = maintenant.getMonth() + 1

  switch (granularite) {
    case 'trimestre':
      return `${annee}-T${trimestreDe(mois)}`
    case 'mois':
      return `${annee}-${String(mois).padStart(2, '0')}`
    case 'jour':
      return maintenant.toISOString().slice(0, 10)
    default:
      return ''
  }
}

/**
 * `dateIso` : une date/heure ISO (periode "Y-m-d" ou horodatage RFC 3339) —
 * comparée par découpage de chaîne, jamais via `new Date()`, pour ne pas
 * introduire de décalage de fuseau entre le filtre (heure locale du
 * navigateur) et une date déjà au format "Y-m-d" (sans heure).
 */
export function correspondAPeriode(dateIso: string, granularite: GranularitePeriode, valeur: string): boolean {
  if (granularite === 'aucun' || valeur === '' || dateIso.length < 10) {
    return true
  }

  const annee = dateIso.slice(0, 4)
  const mois = Number(dateIso.slice(5, 7))

  switch (granularite) {
    case 'trimestre': {
      const [anneeValeur, trimestreValeur] = valeur.split('-T')

      return annee === anneeValeur && trimestreDe(mois) === Number(trimestreValeur)
    }
    case 'mois':
      return dateIso.slice(0, 7) === valeur
    case 'jour':
      return dateIso.slice(0, 10) === valeur
    default:
      return true
  }
}

/** État du filtre trimestre/mois/jour piloté par l'URL (`granularite`, `periode`) — partagé par SelecteurPeriode et les pages qui filtrent une liste datée. */
export function usePeriodeFiltre() {
  const [searchParams] = useSearchParams()
  const granularite = (searchParams.get('granularite') as GranularitePeriode | null) ?? 'aucun'
  const valeur = searchParams.get('periode') ?? ''

  return {
    granularite,
    valeur,
    correspond: (dateIso: string) => correspondAPeriode(dateIso, granularite, valeur),
  }
}
