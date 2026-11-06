import { describe, expect, it } from 'vitest'
import { normaliserNombreDecimal } from './nombre-decimal'

describe('normaliserNombreDecimal', () => {
  it('accepte la virgule décimale et les séparateurs de milliers', () => {
    expect(normaliserNombreDecimal('0,5')).toBe('0.5')
    expect(normaliserNombreDecimal('1 000')).toBe('1000')
    expect(normaliserNombreDecimal('1 000,25')).toBe('1000.25')
    expect(normaliserNombreDecimal(' -12 ')).toBe('-12')
  })

  it('refuse une unité collée, du texte ou trop de décimales', () => {
    expect(normaliserNombreDecimal('100 %')).toBeNull()
    expect(normaliserNombreDecimal('ND')).toBeNull()
    expect(normaliserNombreDecimal('0,12345')).toBeNull()
    expect(normaliserNombreDecimal('1,2,3')).toBeNull()
  })
})
