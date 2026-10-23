import { useState } from 'react'
import type { QueryKey } from '@tanstack/react-query'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { ChoixTypeDocument } from './ChoixTypeDocument'
import { requalifierPieceJointe, type PieceRequalifiable } from '@/lib/documents'
import { erreurNature, type NatureDocument } from '@/lib/types-document'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface ModifierPieceJointeModaleProps {
  piece: PieceRequalifiable
  /** Requêtes à rafraîchir après l'enregistrement — la liste où la pièce est affichée. */
  invalider: QueryKey[]
  onFermer: () => void
}

/**
 * Corriger l'intitulé ou le type d'un document déjà déposé (présentation au
 * PHASAOC) sans le retirer ni le redéposer : sa date et son auteur restent.
 * Le fichier lui-même ne change pas.
 */
export function ModifierPieceJointeModale({ piece, invalider, onFermer }: ModifierPieceJointeModaleProps) {
  const [libelle, setLibelle] = useState(piece.libelle ?? '')
  const [nature, setNature] = useState<NatureDocument>({ type: piece.typeDocument ?? '', precision: piece.typeDocumentPrecision ?? '' })
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  async function enregistrer() {
    const probleme = erreurNature(nature)
    if (probleme !== null) {
      setErreur(probleme)

      return
    }
    setErreur(null)
    setEnCours(true)
    try {
      await requalifierPieceJointe(piece.uuid, { libelle, nature })
      await Promise.all(invalider.map((queryKey) => queryClient.invalidateQueries({ queryKey })))
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre="Modifier le document" onFermer={onFermer}>
      {erreur !== null && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}
      <div className="flex flex-col gap-4">
        <p className="text-sm text-muted">
          Fichier : <span className="break-all text-text">{piece.nomFichier}</span>
        </p>
        <div className="flex flex-col gap-1">
          <label htmlFor="piece-libelle">Intitulé</label>
          <input
            id="piece-libelle"
            value={libelle}
            maxLength={255}
            disabled={enCours}
            onChange={(e) => setLibelle(e.target.value)}
            placeholder={piece.nomFichier}
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
          />
          <p className="text-xs text-muted">Laissé vide, le nom du fichier est affiché.</p>
        </div>
        <ChoixTypeDocument id="piece-type" nature={nature} onChange={setNature} disabled={enCours} />
      </div>
      <div className="mt-5 flex justify-end gap-3 border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Annuler
        </Bouton>
        <Bouton disabled={enCours} onClick={() => void enregistrer()}>
          {enCours ? 'Enregistrement…' : 'Enregistrer'}
        </Bouton>
      </div>
    </Modale>
  )
}
