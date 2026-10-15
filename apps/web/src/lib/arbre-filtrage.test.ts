import { describe, expect, it } from 'vitest'
import { FILTRES_VIDES, filtrerArbre } from './arbre-filtrage'
import type { NoeudComposante } from './composantes'

const montants = { notifie: null, engage: null, depense: null }
const arbre: NoeudComposante[] = [
  {
    uuid: 'c1',
    code: '1',
    libelle: 'Composante 1',
    ...montants,
    sousComposantes: [
      {
        uuid: 'sc1',
        code: '1.5',
        libelle: 'Statistiques sectorielles',
        ...montants,
        activites: [
          {
            uuid: 'a1',
            code: '1.5.3',
            libelle: 'Autres statistiques administratives',
            statut: 'en_cours',
            structureResponsable: { uuid: 's1', sigle: 'DDC' },
            ...montants,
            sousActivites: [
              { uuid: 'l1', libelle: 'Référentiel des OCB (RNOCB)', statut: 'planifiee', echeance: null, ...montants },
              { uuid: 'l2', libelle: 'Statistiques du tourisme', statut: 'planifiee', echeance: null, ...montants },
            ],
          },
        ],
      },
    ],
  },
] as NoeudComposante[]

describe('filtrerArbre — recherche par nom de ligne', () => {
  it('retrouve une activité par le nom de l’une de ses lignes, réduite à cette ligne', () => {
    const resultat = filtrerArbre(arbre, { ...FILTRES_VIDES, recherche: 'rnocb' })
    const activite = resultat[0].sousComposantes?.[0].activites?.[0]
    expect(activite?.code).toBe('1.5.3')
    expect(activite?.sousActivites?.map((sa) => sa.uuid)).toEqual(['l1'])
  })

  it('garde toutes les lignes quand la recherche porte sur l’activité elle-même', () => {
    const resultat = filtrerArbre(arbre, { ...FILTRES_VIDES, recherche: '1.5.3' })
    expect(resultat[0].sousComposantes?.[0].activites?.[0].sousActivites).toHaveLength(2)
  })

  it('n’affiche rien quand ni activité ni ligne ne correspond', () => {
    expect(filtrerArbre(arbre, { ...FILTRES_VIDES, recherche: 'enes' })).toHaveLength(0)
  })
})
