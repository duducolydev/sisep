import { useState } from 'react'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { IconeBouton, IconePlus, IconeAnnuler, IconeDeplierTout, IconeReplierTout } from './Icones'
import { creerGabaritEtapes, modifierGabaritEtapes, type GabaritEtapes } from '@/lib/etapes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface GabaritEtapesModaleProps {
  /** `undefined` = création ; sinon le gabarit est pré-rempli et modifié. */
  gabarit?: GabaritEtapes
  onFermer: () => void
}

/**
 * Création et modification d'un gabarit d'étapes (§ 6 du CR du 22/09/2026).
 * L'ordre des étapes dans la liste fait foi — c'est la seule chose qui les
 * distingue, d'où les deux flèches de déplacement plutôt qu'un champ d'ordre
 * à saisir.
 */
export function GabaritEtapesModale({ gabarit, onFermer }: GabaritEtapesModaleProps) {
  const [libelle, setLibelle] = useState(gabarit?.libelle ?? '')
  const [description, setDescription] = useState(gabarit?.description ?? '')
  const [etapes, setEtapes] = useState<string[]>(gabarit?.etapes.map((e) => e.libelle) ?? [''])
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  function changerEtape(index: number, valeur: string) {
    setEtapes((precedentes) => precedentes.map((e, i) => (i === index ? valeur : e)))
  }

  function retirerEtape(index: number) {
    setEtapes((precedentes) => precedentes.filter((_, i) => i !== index))
  }

  function deplacer(index: number, sens: -1 | 1) {
    setEtapes((precedentes) => {
      const cible = index + sens
      if (cible < 0 || cible >= precedentes.length) return precedentes
      const suivantes = [...precedentes]
      const [deplacee] = suivantes.splice(index, 1)
      suivantes.splice(cible, 0, deplacee)

      return suivantes
    })
  }

  async function enregistrer() {
    const etapesNettoyees = etapes.map((e) => e.trim()).filter((e) => e !== '')
    if (libelle.trim() === '') {
      setErreur('Le libellé du modèle est requis.')

      return
    }
    if (etapesNettoyees.length === 0) {
      setErreur('Un modèle doit comporter au moins une étape.')

      return
    }

    setErreur(null)
    setEnCours(true)
    try {
      if (gabarit) {
        await modifierGabaritEtapes(gabarit.uuid, {
          libelle: libelle.trim(),
          description: description.trim() === '' ? null : description.trim(),
          etapes: etapesNettoyees,
        })
      } else {
        await creerGabaritEtapes({
          libelle: libelle.trim(),
          description: description.trim() === '' ? undefined : description.trim(),
          etapes: etapesNettoyees,
        })
      }
      await queryClient.invalidateQueries({ queryKey: ['gabarits-etapes'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre={gabarit ? 'Modifier le modèle' : "Nouveau modèle d'étapes"} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      {gabarit && (
        <p className="mb-4 rounded-[var(--radius-controle)] bg-nested px-3 py-2 text-sm text-muted">
          Les activités déjà structurées avec ce gabarit gardent leurs étapes : la modification ne vaut que pour les
          prochaines instanciations.
        </p>
      )}

      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="gabarit-libelle">Libellé</label>
          <input
            id="gabarit-libelle"
            value={libelle}
            disabled={enCours}
            onChange={(e) => setLibelle(e.target.value)}
            placeholder="ex. Opération de collecte"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
          />
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="gabarit-description">Description (optionnel)</label>
          <textarea
            id="gabarit-description"
            rows={2}
            value={description}
            disabled={enCours}
            onChange={(e) => setDescription(e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
          />
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-sm font-semibold">Étapes, dans l'ordre</span>
            <Bouton variante="discret" taille="mini" icone={<IconePlus />} onClick={() => setEtapes((p) => [...p, ''])}>
              Ajouter
            </Bouton>
          </div>

          <ul className="flex flex-col gap-2">
            {etapes.map((etape, index) => (
              <li key={index} className="flex items-center gap-2">
                <span className="chiffres w-5 shrink-0 text-sm text-muted">{index + 1}</span>
                <input
                  value={etape}
                  disabled={enCours}
                  onChange={(e) => changerEtape(index, e.target.value)}
                  placeholder="ex. Déploiement sur le terrain"
                  className="flex-1 rounded-[var(--radius-controle)] border border-border px-3 py-1.5 text-sm"
                />
                <IconeBouton libelle="Monter" disabled={enCours || index === 0} onClick={() => deplacer(index, -1)}>
                  <IconeReplierTout />
                </IconeBouton>
                <IconeBouton
                  libelle="Descendre"
                  disabled={enCours || index === etapes.length - 1}
                  onClick={() => deplacer(index, 1)}
                >
                  <IconeDeplierTout />
                </IconeBouton>
                <IconeBouton
                  libelle="Retirer l'étape"
                  disabled={enCours}
                  onClick={() => retirerEtape(index)}
                  classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                >
                  <IconeAnnuler />
                </IconeBouton>
              </li>
            ))}
          </ul>
        </div>
      </div>

      <div className="mt-5 flex justify-end gap-3 border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Annuler
        </Bouton>
        <Bouton onClick={() => void enregistrer()} disabled={enCours}>
          {enCours ? 'Enregistrement…' : 'Enregistrer'}
        </Bouton>
      </div>
    </Modale>
  )
}
