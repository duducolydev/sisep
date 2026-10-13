import { describe, expect, it } from 'vitest'
import { filtrerProjets, type Initiative, type LienPtba } from './initiatives'

const lien: LienPtba = { type: 'composante', uuid: 'c1', code: '1', libelle: 'Composante 1', tauxExecutionPtba: null }

function projet(uuid: string, libelle: string, lienPtba: LienPtba | null = null): Initiative {
  return { uuid, libelle, description: null, lienPtba, creePar: { uuid: 'u', nomComplet: 'UGP' }, activites: [] }
}

const sansFiltre = { recherche: '', lienPtba: 'tous', filtresSurActivites: false }

describe('filtrerProjets', () => {
  it("affiche un projet qui n'a encore aucune activité", () => {
    // Régression : un projet tout juste créé n'apparaissait nulle part, la
    // liste étant déduite des activités — et sans projet visible, aucun
    // moyen d'y ajouter la première.
    const projets = [projet('vide', 'Projet tout juste créé')]

    expect(filtrerProjets(projets, [], sansFiltre)).toHaveLength(1)
  })

  it('masque un projet vide dès qu\'un filtre porte sur les activités', () => {
    const projets = [projet('vide', 'Projet vide'), projet('actif', 'Projet actif')]

    const retenus = filtrerProjets(projets, [{ initiativeUuid: 'actif' }], { ...sansFiltre, filtresSurActivites: true })

    expect(retenus.map((p) => p.uuid)).toEqual(['actif'])
  })

  it("cherche un projet vide par son intitulé", () => {
    const projets = [projet('a', 'Refonte du portail'), projet('b', 'Enquête emploi')]

    const retenus = filtrerProjets(projets, [], { ...sansFiltre, recherche: 'PORTAIL' })

    expect(retenus.map((p) => p.uuid)).toEqual(['a'])
  })

  it('applique le lien au PTBA aux projets vides comme aux autres', () => {
    const projets = [projet('lie', 'Projet lié', lien), projet('indep', 'Projet indépendant')]

    expect(filtrerProjets(projets, [], { ...sansFiltre, lienPtba: 'lie' }).map((p) => p.uuid)).toEqual(['lie'])
    expect(filtrerProjets(projets, [], { ...sansFiltre, lienPtba: 'independant' }).map((p) => p.uuid)).toEqual(['indep'])
  })

  it("garde un projet dont au moins une activité passe les filtres", () => {
    const projets = [projet('a', 'Projet A'), projet('b', 'Projet B')]

    const retenus = filtrerProjets(projets, [{ initiativeUuid: 'b' }], { ...sansFiltre, filtresSurActivites: true })

    expect(retenus.map((p) => p.uuid)).toEqual(['b'])
  })
})
