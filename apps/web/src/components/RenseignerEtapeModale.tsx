import { useState } from 'react'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { renseignerEtape, type EtapeActivite } from '@/lib/etapes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface RenseignerEtapeModaleProps {
  etape: EtapeActivite
  activiteUuid: string
  onFermer: () => void
}

const AUJOURDHUI = new Date().toISOString().slice(0, 10)

/** Les quatre champs du § 6 du CR voyagent ensemble, la date de mise à jour comprise : c'est elle qui permettra de repérer les étapes sans nouvelle. */
export function RenseignerEtapeModale({ etape, activiteUuid, onFermer }: RenseignerEtapeModaleProps) {
  const [avancement, setAvancement] = useState(String(etape.avancement))
  const [valeurActuelle, setValeurActuelle] = useState(etape.valeurActuelle ?? '')
  const [observation, setObservation] = useState(etape.observation ?? '')
  const [dateMiseAJour, setDateMiseAJour] = useState(etape.dateMiseAJour ?? AUJOURDHUI)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  async function enregistrer() {
    const niveau = Number(avancement)
    if (!Number.isFinite(niveau) || niveau < 0 || niveau > 100) {
      setErreur('Le niveau atteint doit être compris entre 0 et 100.')

      return
    }

    setErreur(null)
    setEnCours(true)
    try {
      await renseignerEtape(etape.uuid, {
        avancement: Math.round(niveau),
        valeurActuelle: valeurActuelle.trim() === '' ? null : valeurActuelle.trim(),
        observation: observation.trim() === '' ? null : observation.trim(),
        dateMiseAJour,
      })
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre={etape.libelle} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="etape-avancement">Niveau atteint (%)</label>
            <input
              id="etape-avancement"
              type="number"
              min={0}
              max={100}
              value={avancement}
              disabled={enCours}
              onChange={(e) => setAvancement(e.target.value)}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="etape-date">Date de mise à jour</label>
            <input
              id="etape-date"
              type="date"
              value={dateMiseAJour}
              disabled={enCours}
              onChange={(e) => setDateMiseAJour(e.target.value)}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="etape-valeur">Valeur actuelle (optionnel){etape.unite ? ` — ${etape.unite}` : ''}</label>
          <input
            id="etape-valeur"
            inputMode="decimal"
            value={valeurActuelle}
            disabled={enCours}
            onChange={(e) => setValeurActuelle(e.target.value)}
            placeholder="ex. 12"
            className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
          />
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="etape-observation">Observation (optionnel)</label>
          <textarea
            id="etape-observation"
            rows={3}
            value={observation}
            disabled={enCours}
            onChange={(e) => setObservation(e.target.value)}
            placeholder="Difficultés rencontrées, précisions sur l'avancement…"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
          />
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
