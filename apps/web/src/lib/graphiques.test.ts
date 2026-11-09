import { describe, expect, it } from 'vitest'
import { HAUTEUR_MINIMALE_GRAPHIQUE, hauteurGraphiqueEnBarres, lignesTauxExecution } from './graphiques'

describe('lignesTauxExecution', () => {
  it("annonce le taux exécuté, pas la part restante, quand rien n'est dépensé", () => {
    // Régression : la barre pleine affichait « 100 % » sans aucune dépense.
    const [ligne] = lignesTauxExecution([{ libelle: '2026', tauxExecution: 0 }])

    expect(ligne?.execution).toBe(0)
    expect(ligne?.resteADepenser).toBe(100)
    expect(ligne?.sommet).toBe(100)
    expect(ligne?.etiquette).toBe('0 % exécuté')
  })

  it('garde une décimale et le séparateur français', () => {
    const [ligne] = lignesTauxExecution([{ libelle: '2026', tauxExecution: 0.1234 }])

    expect(ligne?.execution).toBe(12.3)
    expect(ligne?.resteADepenser).toBe(87.7)
    expect(ligne?.etiquette).toBe('12,3 % exécuté')
  })

  it("n'invente pas de 0 % quand le taux est indéfini", () => {
    const [ligne] = lignesTauxExecution([{ libelle: '2027', tauxExecution: null }])

    expect(ligne).toEqual({ libelle: '2027', execution: null, resteADepenser: null, sommet: null, etiquette: null })
  })
})

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
