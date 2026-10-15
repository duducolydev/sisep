import { describe, expect, it } from 'vitest'
import { correspondAPeriode } from './periode'

describe('correspondAPeriode', () => {
  it('laisse tout passer quand la granularité est "aucun"', () => {
    expect(correspondAPeriode('2026-09-18', 'aucun', '2026-09')).toBe(true)
  })

  it('laisse tout passer quand la valeur de filtre est vide', () => {
    expect(correspondAPeriode('2026-09-18', 'jour', '')).toBe(true)
  })

  it('compare au jour près', () => {
    expect(correspondAPeriode('2026-09-18', 'jour', '2026-09-18')).toBe(true)
    expect(correspondAPeriode('2026-09-18', 'jour', '2026-09-19')).toBe(false)
  })

  it('compare au mois près, y compris sur un horodatage complet', () => {
    expect(correspondAPeriode('2026-09-18T10:49:00+00:00', 'mois', '2026-09')).toBe(true)
    expect(correspondAPeriode('2026-10-01T00:00:00+00:00', 'mois', '2026-09')).toBe(false)
  })

  it("compare à l'année près", () => {
    expect(correspondAPeriode('2026-09-18', 'annee', '2026')).toBe(true)
    expect(correspondAPeriode('2025-12-31T23:00:00+00:00', 'annee', '2026')).toBe(false)
  })

  it('compare au trimestre près (mois 7-9 = T3)', () => {
    expect(correspondAPeriode('2026-09-18', 'trimestre', '2026-T3')).toBe(true)
    expect(correspondAPeriode('2026-07-01', 'trimestre', '2026-T3')).toBe(true)
    expect(correspondAPeriode('2026-06-30', 'trimestre', '2026-T3')).toBe(false)
    expect(correspondAPeriode('2025-09-18', 'trimestre', '2026-T3')).toBe(false)
  })
})
