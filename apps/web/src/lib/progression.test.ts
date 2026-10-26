import { describe, expect, it } from 'vitest'
import { progressionComposante, progressionProjet, type NoeudActivite, type NoeudComposante } from './composantes'

const montants = { notifie: null, engage: null, depense: null }

function activite(code: string, avancement: number | null): NoeudActivite {
  return { uuid: code, code, libelle: code, statut: 'en_cours', structureResponsable: { uuid: 's', sigle: 'S' }, ...montants, avancement }
}

function composante(code: string, avancements: (number | null)[]): NoeudComposante {
  return {
    uuid: code,
    code,
    libelle: code,
    ...montants,
    sousComposantes: [{ uuid: `${code}.1`, code: `${code}.1`, libelle: '', ...montants, activites: avancements.map((a, i) => activite(`${code}.1.${i}`, a)) }],
  }
}

describe('progression des étapes', () => {
  it("d'une composante : moyenne de ses activités suivies, les autres ignorées", () => {
    expect(progressionComposante(composante('1', [40, 60, null]))).toEqual({ progression: 50, suivies: 2 })
  })

  it("aucune activité suivie : null, jamais 0", () => {
    expect(progressionComposante(composante('1', [null, null]))).toEqual({ progression: null, suivies: 0 })
    expect(progressionProjet([composante('1', [null])]).progression).toBeNull()
  })

  it('du projet : moyenne des composantes suivies, comme la page Composantes', () => {
    // Composante 1 : (40 + 60) / 2 = 50 ; composante 2 : 80 ; composante 3 non suivie.
    // Moyenne des composantes : 65 — et non (40 + 60 + 80) / 3 ≈ 60 par activité.
    const resultat = progressionProjet([composante('1', [40, 60]), composante('2', [80]), composante('3', [null])])
    expect(resultat.progression).toBe(65)
    expect(resultat.suivies).toBe(3)
  })
})
