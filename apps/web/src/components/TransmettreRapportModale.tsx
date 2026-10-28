import { useEffect, useMemo, useState } from 'react'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { DropzoneFichier } from './DropzoneFichier'
import { IconeTransmettre } from './Icones'
import { useToast } from './ToastProvider'
import { formaterTaille } from '@/lib/documents'
import { formaterDate } from '@/lib/format'
import { transmettreRapport, type Rapport } from '@/lib/parties-prenantes'
import { queryClient } from '@/lib/query-client'

/** Miroir de RapportController::TAILLE_MAX_OCTETS. */
const TAILLE_MAX_OCTETS = 10 * 1024 * 1024

/** Formats que le navigateur sait afficher : le reste se vérifie dans son logiciel. */
const APERCU_PDF = ['application/pdf']
const APERCU_IMAGE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']

interface TransmettreRapportModaleProps {
  rapport: Rapport
  onFermer: () => void
}

/**
 * Transmettre un rapport trimestriel après l'avoir vu : le fichier choisi
 * s'affiche dans la fenêtre (PDF, image) avant tout envoi — un mauvais
 * fichier ou une mauvaise période ne part plus d'un simple clic. Rien n'est
 * envoyé au serveur tant que « Transmettre ce rapport » n'est pas confirmé.
 */
export function TransmettreRapportModale({ rapport, onFermer }: TransmettreRapportModaleProps) {
  const [fichier, setFichier] = useState<File | null>(null)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)
  const afficherToast = useToast()

  // L'aperçu lit le fichier sur le poste, sans l'envoyer.
  const url = useMemo(() => (fichier ? URL.createObjectURL(fichier) : null), [fichier])
  useEffect(() => () => {
    if (url) URL.revokeObjectURL(url)
  }, [url])

  const tropLourd = fichier !== null && fichier.size > TAILLE_MAX_OCTETS
  const genre = fichier === null ? null : APERCU_PDF.includes(fichier.type) ? 'pdf' : APERCU_IMAGE.includes(fichier.type) ? 'image' : 'autre'
  const periode = `${rapport.periode} ${rapport.exercice}`

  async function transmettre() {
    if (!fichier || tropLourd) return
    setErreur(null)
    setEnCours(true)
    try {
      await transmettreRapport(rapport.uuid, fichier)
      await queryClient.invalidateQueries({ queryKey: ['rapports'] })
      afficherToast(`Rapport ${periode} transmis.`)
      onFermer()
    } catch (e) {
      setErreur(e instanceof Error ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre={`Transmettre le rapport ${periode}`} onFermer={onFermer} taille="large">
      <p className="mb-4 text-sm text-muted">
        {rapport.structure.sigle} · échéance {formaterDate(rapport.dateEcheance)}. Vérifiez le fichier avant de le transmettre : une fois
        transmis, il est reçu par l'UGP.
      </p>

      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      {fichier === null ? (
        <div className="flex flex-col gap-1">
          <DropzoneFichier fichier={null} onChange={setFichier} />
          <p className="text-xs text-muted">10 Mo au plus. Un PDF s'affiche ici avant l'envoi.</p>
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-[var(--radius-controle)] bg-nested px-3 py-2 text-sm">
            <span className="min-w-0 truncate font-semibold" title={fichier.name}>
              {fichier.name}
            </span>
            <span className="chiffres shrink-0 text-muted">{formaterTaille(fichier.size)}</span>
          </div>

          {tropLourd && (
            <p role="alert" className="rounded-[var(--radius-controle)] bg-bad-bg px-3 py-2 text-sm text-bad">
              Fichier trop volumineux : 10 Mo au plus.
            </p>
          )}

          {url && genre === 'pdf' && (
            <iframe title={`Aperçu de ${fichier.name}`} src={url} className="h-[60vh] w-full rounded-[var(--radius-controle)] border border-border" />
          )}
          {url && genre === 'image' && (
            <img src={url} alt={`Aperçu de ${fichier.name}`} className="max-h-[60vh] w-full rounded-[var(--radius-controle)] border border-border object-contain" />
          )}
          {url && genre === 'autre' && (
            <div className="rounded-[var(--radius-controle)] border border-dashed border-border px-4 py-6 text-center text-sm text-muted">
              <p>Aperçu impossible dans le navigateur pour ce format.</p>
              <p className="mt-1">
                <a href={url} download={fichier.name} className="font-semibold text-accent hover:underline">
                  Ouvrir le fichier
                </a>{' '}
                pour le vérifier dans son logiciel, ou transmettez-le en PDF.
              </p>
            </div>
          )}
        </div>
      )}

      <div className="mt-5 flex flex-wrap justify-end gap-3 border-t border-border pt-4">
        {fichier && (
          <Bouton variante="secondaire" onClick={() => setFichier(null)} disabled={enCours}>
            Changer de fichier
          </Bouton>
        )}
        <Bouton variante="secondaire" onClick={onFermer} disabled={enCours}>
          Annuler
        </Bouton>
        <Bouton icone={<IconeTransmettre />} disabled={!fichier || tropLourd || enCours} onClick={() => void transmettre()}>
          {enCours ? 'Envoi…' : 'Transmettre ce rapport'}
        </Bouton>
      </div>
    </Modale>
  )
}
