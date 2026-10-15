import { describe, expect, it } from 'vitest'
import { formaterFcfa, formaterMds, formaterTaux, formaterValeurIndicateur } from './format'

describe('formaterFcfa', () => {
  it('formate un montant entier en FCFA avec séparateurs français', () => {
    expect(formaterFcfa(780_000_000)).toMatch(/^780.000.000 FCFA$/u)
  })
})

describe('formaterTaux', () => {
  it('affiche un tiret quand le taux est null (division par zéro, § 5.1)', () => {
    expect(formaterTaux(null)).toBe('—')
  })

  it('formate un taux en pourcentage', () => {
    expect(formaterTaux(0.72)).toMatch(/^72.%$/u)
  })
})

describe('formaterMds', () => {
  it('convertit un montant FCFA en milliards à deux décimales', () => {
    expect(formaterMds(26_400_000_000)).toMatch(/^26,40$/u)
  })
})

describe('formaterValeurIndicateur', () => {
  it("n'ajoute pas de décimale à un dénombrement", () => {
    expect(formaterValeurIndicateur('19550.0000')).toMatch(/^19.550$/u)
  })

  it('garde les décimales significatives', () => {
    expect(formaterValeurIndicateur('82.5000')).toBe('82,5')
  })
})
