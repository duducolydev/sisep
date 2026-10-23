import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import {
  creerComposante,
  creerSousComposante,
  elementPtbaQueryOptions,
  modifierElementPtba,
  structuresQueryOptions,
  type NiveauPtba,
} from '@/lib/composantes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const NOMS: Record<NiveauPtba, string> = {
  composante: 'la composante',
  sous_composante: 'la sous-composante',
  activite: "l'activité",
}

interface ElementPtbaModaleProps {
  niveau: NiveauPtba
  /** Présent : modification ; absent : création (composante ou sous-composante). */
  uuid?: string
  /** Composante parente, pour créer une sous-composante. */
  parentUuid?: string
  /** Préfixe proposé pour le code d'un nouvel élément (ex. « 1. » sous la composante 1). */
  prefixeCode?: string
  onFermer: () => void
}

/**
 * Créer ou modifier un élément du PTBA — composante, sous-composante,
 * activité (code, libellé, description ; structure responsable pour une
 * activité). La création d'une activité garde sa propre fenêtre, qui
 * demande aussi la structure responsable.
 */
export function ElementPtbaModale({ niveau, uuid, parentUuid, prefixeCode = '', onFermer }: ElementPtbaModaleProps) {
  const { data: element } = useQuery(elementPtbaQueryOptions(niveau, uuid))
  const { data: structures } = useQuery({ ...structuresQueryOptions(), enabled: niveau === 'activite' })
  const [code, setCode] = useState(prefixeCode)
  const [libelle, setLibelle] = useState('')
  const [description, setDescription] = useState('')
  const [structure, setStructure] = useState('')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  useEffect(() => {
    if (element) {
      setCode(element.code)
      setLibelle(element.libelle)
      setDescription(element.description ?? '')
      setStructure(element.structureResponsable?.uuid ?? '')
    }
  }, [element])

  async function enregistrer() {
    if (code.trim() === '' || libelle.trim() === '') {
      setErreur('Le code et le libellé sont requis.')

      return
    }
    setErreur(null)
    setEnCours(true)
    const champs = { code: code.trim(), libelle: libelle.trim(), description: description.trim() || null }
    try {
      if (uuid) {
        await modifierElementPtba(niveau, uuid, {
          ...champs,
          ...(niveau === 'activite' && structure !== '' && structure !== element?.structureResponsable?.uuid ? { structureResponsable: structure } : {}),
        })
      } else if (niveau === 'composante') {
        await creerComposante(champs)
      } else if (niveau === 'sous_composante' && parentUuid) {
        await creerSousComposante({ composante: parentUuid, ...champs })
      }
      await queryClient.invalidateQueries({ queryKey: ['arbre'] })
      await queryClient.invalidateQueries({ queryKey: ['ptba'] })
      await queryClient.invalidateQueries({ queryKey: ['activite'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  const champ = 'rounded-[var(--radius-controle)] border border-border px-3 py-2'
  const titre = uuid ? `Modifier ${NOMS[niveau]}` : niveau === 'composante' ? 'Nouvelle composante' : 'Nouvelle sous-composante'

  return (
    <Modale titre={titre} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}
      {uuid && !element ? (
        <p className="text-muted">Chargement…</p>
      ) : (
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-[8rem_1fr] gap-4">
            <div className="flex flex-col gap-1">
              <label htmlFor="ptba-code">Code</label>
              <input id="ptba-code" value={code} maxLength={20} onChange={(e) => setCode(e.target.value)} className={`chiffres ${champ}`} />
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="ptba-libelle">Libellé</label>
              <input id="ptba-libelle" value={libelle} onChange={(e) => setLibelle(e.target.value)} className={champ} />
            </div>
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="ptba-description">Description (optionnel)</label>
            <textarea id="ptba-description" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} className={champ} />
          </div>
          {niveau === 'activite' && (
            <div className="flex flex-col gap-1">
              <label htmlFor="ptba-structure">Structure responsable</label>
              <select id="ptba-structure" value={structure} onChange={(e) => setStructure(e.target.value)} className={champ}>
                {(structures?.donnees ?? []).map((s) => (
                  <option key={s.uuid} value={s.uuid}>
                    {s.sigle}
                  </option>
                ))}
              </select>
              <p className="text-xs text-muted">Changer la structure change qui voit et renseigne l'activité.</p>
            </div>
          )}
        </div>
      )}
      <div className="mt-5 flex justify-end gap-3 border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Annuler
        </Bouton>
        <Bouton disabled={enCours || (uuid !== undefined && !element)} onClick={() => void enregistrer()}>
          {enCours ? 'Enregistrement…' : uuid ? 'Enregistrer' : 'Créer'}
        </Bouton>
      </div>
    </Modale>
  )
}
