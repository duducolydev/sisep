import { formaterDate } from './format'
import { trimestreDe } from './periode'

export type GranulariteSerie = 'saisie' | 'trimestre' | 'annee'

export interface PointSerie {
  /** Date de la période, "Y-m-d". */
  periode: string
  valeur: number
  /** Saisie non encore validée. */
  enAttente: boolean
}

export interface PointAgrege extends PointSerie {
  /** Libellé d'abscisse : la date, « T3 2026 » ou « 2026 ». */
  libelle: string
}

/**
 * Regroupe la série d'un indicateur par trimestre ou par année (retours des
 * RSE). Un indicateur est un niveau, pas un flux : la valeur d'un trimestre
 * est la dernière saisie du trimestre, jamais une somme.
 */
export function agregerSerie(points: PointSerie[], granularite: GranulariteSerie): PointAgrege[] {
  const tries = [...points].sort((a, b) => a.periode.localeCompare(b.periode))
  const parCle = new Map<string, PointAgrege>()

  for (const point of tries) {
    const annee = point.periode.slice(0, 4)
    const trimestre = trimestreDe(Number(point.periode.slice(5, 7)))
    const [cle, libelle] =
      granularite === 'annee'
        ? [annee, annee]
        : granularite === 'trimestre'
          ? [`${annee}-T${trimestre}`, `T${trimestre} ${annee}`]
          : [point.periode, formaterDate(point.periode)]

    // Ordre croissant : la dernière écriture de la clé est la plus récente.
    parCle.set(cle, { ...point, libelle })
  }

  return [...parCle.values()]
}
