import { describe, expect, it } from 'vitest'
import { agregerSerie, type PointSerie } from './serie-indicateur'

const points: PointSerie[] = [
  { periode: '2026-08-31', valeur: 4, enAttente: false },
  { periode: '2025-12-31', valeur: 1, enAttente: false },
  { periode: '2026-07-15', valeur: 3, enAttente: false },
  { periode: '2026-10-14', valeur: 6, enAttente: true },
]

describe('agregerSerie', () => {
  it('garde chaque saisie, dans l’ordre chronologique', () => {
    expect(agregerSerie(points, 'saisie').map((p) => p.valeur)).toEqual([1, 3, 4, 6])
  })

  it('retient la dernière valeur de chaque trimestre, pas la somme', () => {
    const serie = agregerSerie(points, 'trimestre')
    expect(serie.map((p) => [p.libelle, p.valeur])).toEqual([
      ['T4 2025', 1],
      ['T3 2026', 4],
      ['T4 2026', 6],
    ])
  })

  it('retient la dernière valeur de chaque année et conserve son statut', () => {
    const serie = agregerSerie(points, 'annee')
    expect(serie.map((p) => [p.libelle, p.valeur, p.enAttente])).toEqual([
      ['2025', 1, false],
      ['2026', 6, true],
    ])
  })
})
