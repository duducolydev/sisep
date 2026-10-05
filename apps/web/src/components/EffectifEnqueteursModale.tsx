import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { ComboboxSimple } from './ComboboxSimple'
import {
  creerEffectifEnqueteurs,
  modifierEffectifEnqueteurs,
  type EffectifEnqueteurs,
} from '@/lib/rh'
import { budgetExecutionQueryOptions } from '@/lib/budget'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface EffectifEnqueteursModaleProps {
  effectif?: EffectifEnqueteurs
  onFermer: () => void
}

/** Effectif d'enquêteurs d'une opération (§ 10 du CR du 22/09/2026) : suivi agrégé, avec désagrégation par sexe — jamais nominatif. */
export function EffectifEnqueteursModale({ effectif, onFermer }: EffectifEnqueteursModaleProps) {
  const { data: activites } = useQuery(budgetExecutionQueryOptions('activite'))

  const [activite, setActivite] = useState(effectif?.activite.uuid ?? '')
  const [libelle, setLibelle] = useState(effectif?.libelle ?? '')
  const [annee, setAnnee] = useState(String(effectif?.annee ?? new Date().getFullYear()))
  const [femmes, setFemmes] = useState(String(effectif?.nombreFemmes ?? 0))
  const [hommes, setHommes] = useState(String(effectif?.nombreHommes ?? 0))
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  const total = (Number(femmes) || 0) + (Number(hommes) || 0)

  async function enregistrer() {
    if (activite === '') {
      setErreur("L'opération de rattachement est requise.")

      return
    }

    setErreur(null)
    setEnCours(true)
    const entree = {
      activite,
      annee: Number(annee),
      libelle: libelle.trim() === '' ? null : libelle.trim(),
      nombreFemmes: Number(femmes) || 0,
      nombreHommes: Number(hommes) || 0,
    }

    try {
      if (effectif) {
        await modifierEffectifEnqueteurs(effectif.uuid, entree)
      } else {
        await creerEffectifEnqueteurs(entree)
      }
      await queryClient.invalidateQueries({ queryKey: ['rh'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre={effectif ? "Modifier l'effectif" : "Effectif d'enquêteurs"} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-1 text-sm">
          <label htmlFor="effectif-activite">Opération</label>
          <ComboboxSimple
            id="effectif-activite"
            options={(activites?.donnees ?? []).map((a) => ({ value: a.uuid, label: `${a.code} — ${a.libelle}` }))}
            valeur={activite}
            onChange={setActivite}
            placeholder="Choisir une opération…"
            disabled={enCours}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="effectif-libelle">Vague de collecte (optionnel)</label>
            <input
              id="effectif-libelle"
              value={libelle}
              disabled={enCours}
              onChange={(e) => setLibelle(e.target.value)}
              placeholder="ex. Collecte principale"
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="effectif-annee">Année</label>
            <input
              id="effectif-annee"
              type="number"
              value={annee}
              disabled={enCours}
              onChange={(e) => setAnnee(e.target.value)}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="effectif-femmes">Femmes</label>
            <input
              id="effectif-femmes"
              type="number"
              min={0}
              value={femmes}
              disabled={enCours}
              onChange={(e) => setFemmes(e.target.value)}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="effectif-hommes">Hommes</label>
            <input
              id="effectif-hommes"
              type="number"
              min={0}
              value={hommes}
              disabled={enCours}
              onChange={(e) => setHommes(e.target.value)}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
        </div>

        <p className="text-sm text-muted">
          Effectif total : <span className="chiffres font-semibold text-text">{total}</span> — déduit de la somme, il ne se
          saisit pas.
        </p>
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
