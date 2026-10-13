import { describe, expect, it } from 'vitest'
import { HAUTEUR_MINIMALE_GRAPHIQUE, hauteurGraphiqueEnBarres } from './graphiques'

describe('hauteurGraphiqueEnBarres', () => {
  it("réserve au moins la hauteur minimale, même pour une seule ligne", () => {
    // Régression : une ligne donnait un cadre d'environ 50 px pour un
    // graphique de 160 px, qui débordait sur la carte du dessous.
    expect(hauteurGraphiqueEnBarres(1, 28)).toBe(HAUTEUR_MINIMALE_GRAPHIQUE)
  })

  it('grandit avec le nombre de lignes, axe compris', () => {
    expect(hauteurGraphiqueEnBarres(12, 28)).toBe(12 * 28 + 48)
  })

  it('ne descend jamais sous la hauteur minimale', () => {
    for (let lignes = 0; lignes <= 20; lignes++) {
      expect(hauteurGraphiqueEnBarres(lignes, 24)).toBeGreaterThanOrEqual(HAUTEUR_MINIMALE_GRAPHIQUE)
    }
  })
})
