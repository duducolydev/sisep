import { useState } from 'react'
import { usePeutSur } from '@/lib/use-permissions'
import { ApiError } from '@/lib/api-client'
import { useToast } from './ToastProvider'
import { Bouton } from './Bouton'
import { IconeValider } from './Icones'

interface ValiderClotureBoutonProps {
  statut: string
  avancement: number | null
  structureResponsableUuid: string | null
  onValider: () => Promise<unknown>
}

/**
 * Clôturer (statut « Terminée ») exige activites.valider, distinct de
 * activites.modifier qui gouverne la saisie de l'avancement — même
 * séparation saisie/validation que le budget et les indicateurs (§ 7.2).
 * N'apparaît qu'une fois l'avancement à 100 %, pour ne pas suggérer une
 * clôture prématurée.
 */
export function ValiderClotureBouton({ statut, avancement, structureResponsableUuid, onValider }: ValiderClotureBoutonProps) {
  const peutValider = usePeutSur('activites.valider', structureResponsableUuid)
  const afficherToast = useToast()
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  if (!peutValider || statut === 'terminee' || avancement !== 100) {
    return null
  }

  async function valider() {
    setErreur(null)
    setEnCours(true)
    try {
      await onValider()
      afficherToast('Activité marquée terminée.')
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <span className="inline-flex items-center gap-1.5">
      <Bouton variante="discret" taille="mini" icone={<IconeValider />} onClick={valider} disabled={enCours}>
        {enCours ? 'Validation…' : 'Marquer terminée'}
      </Bouton>
      {erreur && (
        <span role="alert" className="text-xs text-bad">
          {erreur}
        </span>
      )}
    </span>
  )
}
