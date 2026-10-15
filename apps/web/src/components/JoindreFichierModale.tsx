import { useState } from 'react'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { DropzoneFichier } from './DropzoneFichier'
import { ChoixTypeDocument } from './ChoixTypeDocument'
import { erreurNature, NATURE_VIDE, type NatureDocument } from '@/lib/types-document'

interface JoindreFichierModaleProps {
  titre: string
  /** Taille maximale acceptée par le serveur pour ce dépôt. */
  tailleMaxOctets: number
  onEnvoyer: (fichier: File, nature: NatureDocument) => Promise<unknown>
  onFermer: () => void
}

/** Joindre un justificatif : le fichier et sa nature (rapport, procès-verbal, liste de présence…), choisie avant l'envoi. */
export function JoindreFichierModale({ titre, tailleMaxOctets, onEnvoyer, onFermer }: JoindreFichierModaleProps) {
  const [fichier, setFichier] = useState<File | null>(null)
  const [nature, setNature] = useState<NatureDocument>(NATURE_VIDE)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)
  const maxMo = Math.round(tailleMaxOctets / (1024 * 1024))

  async function envoyer() {
    const probleme =
      fichier === null
        ? 'Choisissez un fichier.'
        : fichier.size > tailleMaxOctets
          ? `Fichier trop volumineux : la taille maximale est de ${maxMo} Mo.`
          : erreurNature(nature)
    if (probleme !== null || fichier === null) {
      setErreur(probleme)

      return
    }

    setErreur(null)
    setEnCours(true)
    try {
      await onEnvoyer(fichier, nature)
      onFermer()
    } catch (e) {
      setErreur(e instanceof Error ? e.message : 'Le dépôt a échoué. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre={titre} onFermer={onFermer}>
      {erreur !== null && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}
      <div className="flex flex-col gap-4">
        <ChoixTypeDocument nature={nature} onChange={setNature} disabled={enCours} />
        <div className="flex flex-col gap-1">
          <DropzoneFichier fichier={fichier} onChange={setFichier} />
          <p className="text-xs text-muted">{maxMo} Mo au plus.</p>
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-3 border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Annuler
        </Bouton>
        <Bouton disabled={enCours} onClick={() => void envoyer()}>
          {enCours ? 'Envoi…' : 'Joindre'}
        </Bouton>
      </div>
    </Modale>
  )
}
