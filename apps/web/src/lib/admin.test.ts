import { describe, expect, it } from 'vitest'
import { entreeAdministration, ongletsAdministration } from './admin'
import { detientGlobalement, type ProfilUtilisateur } from './auth'

const ADMINISTRATION = ['indicateurs.definir', 'admin.parametres', 'admin.utilisateurs', 'admin.roles', 'journal.consulter']

/** Profil dont chaque code porte la portée donnée : `null` = global, sinon les structures. */
function profil(codes: string[], portee: string[] | null = null): ProfilUtilisateur {
  return {
    uuid: 'u',
    email: 'agent@ansd.sn',
    nom: 'Agent',
    prenom: 'Un',
    fonction: null,
    structure: null,
    structureUuid: null,
    doitChangerMdp: false,
    roles: [],
    permissions: codes,
    permissionsPortee: Object.fromEntries(codes.map((c) => [c, portee])),
    aAccesIndicateurs: false,
  }
}

describe('detientGlobalement', () => {
  it('distingue une attribution globale d\'une attribution limitée à une structure', () => {
    expect(detientGlobalement(profil(['admin.roles']), 'admin.roles')).toBe(true)
    expect(detientGlobalement(profil(['admin.roles'], ['dsid']), 'admin.roles')).toBe(false)
  })

  it('refuse un code absent ou un profil pas encore chargé', () => {
    expect(detientGlobalement(profil([]), 'admin.roles')).toBe(false)
    expect(detientGlobalement(undefined, 'admin.roles')).toBe(false)
  })
})

describe('ongletsAdministration', () => {
  it("n'ouvre rien d'administratif à un administrateur limité à une structure", () => {
    // Régression (29/10/2026, mise en ligne sur sisephasaoc.ansd.sn) : un
    // compte administrateur attribué sur une seule structure voyait tous les
    // onglets, et chacun répondait 403 — le serveur n'honore ces droits qu'en
    // portée globale.
    const limite = profil(ADMINISTRATION, ['ugp'])

    expect(ongletsAdministration(limite).map((o) => o.to)).toEqual(['/administration/parties-prenantes'])
    expect(entreeAdministration(limite)).toBeNull()
  })

  it('ouvre tous les onglets à un administrateur global, en commençant par les indicateurs', () => {
    const global = profil(ADMINISTRATION)

    expect(ongletsAdministration(global)).toHaveLength(7)
    expect(entreeAdministration(global)).toBe('/administration/indicateurs')
  })

  it('fait entrer un coordonnateur par son premier onglet ouvert', () => {
    // Le coordonnateur ne définit pas les indicateurs : le lien du menu ne
    // doit pas pointer sur un onglet qui le renverrait au tableau de bord.
    const coordonnateur = profil(['admin.parametres', 'admin.utilisateurs', 'admin.roles', 'journal.consulter'])

    expect(entreeAdministration(coordonnateur)).toBe('/administration/modeles-etapes')
    expect(ongletsAdministration(coordonnateur).map((o) => o.to)).not.toContain('/administration/indicateurs')
  })

  it("n'affiche pas le lien pour les seuls onglets ouverts à tous", () => {
    expect(entreeAdministration(profil(['activites.consulter']))).toBeNull()
  })
})
