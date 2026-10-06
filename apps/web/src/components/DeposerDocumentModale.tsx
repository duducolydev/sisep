import { useState } from 'react'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { DropzoneFichier } from './DropzoneFichier'
import { deposerDocument, VISIBILITES, type TypeRattachement } from '@/lib/documents'
import { queryClient } from '@/lib/query-client'

interface DeposerDocumentModaleProps {
  type: TypeRattachement
  cibleUuid: string
  cibleLibelle: string
  onFermer: () => void
}

/** Dépôt d'un document dans la bibliothèque — intitulé libre, visibilité, fichier (10 Mo au plus, limite du serveur). */
export function DeposerDocumentModale({ type, cibleUuid, cibleLibelle, onFermer }: DeposerDocumentModaleProps) {
  const [fichier, setFichier] = useState<File | null>(null)
  const [libelle, setLibelle] = useState('')
  const [visibilite, setVisibilite] = useState<string>('tous')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  async function enregistrer() {
    if (fichier === null) {
      setErreur('Choisissez un fichier.')

      return
    }

    setErreur(null)
    setEnCours(true)
    try {
      await deposerDocument(type, cibleUuid, { fichier, libelle: libelle.trim(), visibilite })
      await queryClient.invalidateQueries({ queryKey: ['documents'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof Error ? e.message : 'Le dépôt a échoué. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre="Déposer un document" onFermer={onFermer}>
      {erreur !== null && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <div className="flex flex-col gap-4">
        <p className="text-sm text-muted">
          Rattachement : <span className="font-semibold text-text">{cibleLibelle}</span>
        </p>

        <div className="flex flex-col gap-1">
          <label htmlFor="document-libelle">Intitulé (optionnel)</label>
          <input
            id="document-libelle"
            value={libelle}
            disabled={enCours}
            onChange={(e) => setLibelle(e.target.value)}
            placeholder="ex. Manuel de procédures administratives"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
          />
          <p className="text-xs text-muted">À défaut, le nom du fichier sera affiché tel quel.</p>
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="document-visibilite">Visibilité</label>
          <select
            id="document-visibilite"
            value={visibilite}
            disabled={enCours}
            onChange={(e) => setVisibilite(e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
          >
            {VISIBILITES.map((v) => (
              <option key={v.valeur} value={v.valeur}>
                {v.libelle}
              </option>
            ))}
          </select>
        </div>

        <DropzoneFichier fichier={fichier} onChange={setFichier} />
      </div>

      <div className="mt-5 flex justify-end gap-3 border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Annuler
        </Bouton>
        <Bouton onClick={() => void enregistrer()} disabled={enCours}>
          {enCours ? 'Dépôt…' : 'Déposer'}
        </Bouton>
      </div>
    </Modale>
  )
}
