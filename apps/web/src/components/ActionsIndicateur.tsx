import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { supprimerIndicateur, type Indicateur } from '@/lib/indicateurs'
import { budgetExecutionQueryOptions } from '@/lib/budget'
import { useConfirmer } from '@/lib/confirmation'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { usePeut } from '@/lib/use-permissions'
import { useToast } from './ToastProvider'
import { Bouton } from './Bouton'
import { IconeBouton, IconeModifier, IconeSupprimer } from './Icones'
import { ModifierIndicateurModale } from './ModifierIndicateurModale'

interface ActionsIndicateurProps {
  indicateur: Indicateur
  /** « icones » dans une ligne de tableau, « boutons » en tête de fiche. */
  variante: 'icones' | 'boutons'
  /** Appelé après une suppression réussie (la fiche revient à la liste). */
  onSupprime?: () => void
}

/**
 * Modifier (redéfinir) et supprimer un indicateur — indicateurs.definir,
 * détenue par le RSE et l'administrateur. La suppression est logique : les
 * valeurs saisies restent en base et au journal.
 */
export function ActionsIndicateur({ indicateur, variante, onSupprime }: ActionsIndicateurProps) {
  const peutDefinir = usePeut('indicateurs.definir')
  const [edition, setEdition] = useState(false)
  const [suppressionEnCours, setSuppressionEnCours] = useState(false)
  const { data: composantes } = useQuery({ ...budgetExecutionQueryOptions('composante'), enabled: peutDefinir })
  const confirmer = useConfirmer()
  const afficherToast = useToast()

  if (!peutDefinir) return null

  async function supprimer() {
    const confirme = await confirmer({
      titre: "Supprimer l'indicateur",
      message: `L'indicateur « ${indicateur.code} — ${indicateur.libelle} » disparaîtra de la liste, du cadre de résultats et des exports. Ses valeurs saisies sont conservées et la suppression reste tracée dans le journal.`,
      action: 'Supprimer',
    })
    if (!confirme) return

    setSuppressionEnCours(true)
    try {
      await supprimerIndicateur(indicateur.uuid)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['indicateurs'] }),
        queryClient.invalidateQueries({ queryKey: ['synthese'] }),
      ])
      afficherToast(`Indicateur ${indicateur.code} supprimé.`)
      onSupprime?.()
      // Après la navigation : retirée plus tôt, la fiche encore affichée
      // relançait sa requête et montrait « introuvable » un instant.
      queryClient.removeQueries({ queryKey: ['indicateur', indicateur.uuid] })
    } catch (e) {
      afficherToast(`Suppression impossible : ${e instanceof ApiError ? e.message : 'réessayez.'}`)
    } finally {
      setSuppressionEnCours(false)
    }
  }

  return (
    // Dans la liste, la ligne entière ouvre la fiche au clic : les
    // événements de React remontent même depuis la modale (portail), il
    // faut donc les arrêter ici.
    <span className="contents" onClick={(e) => e.stopPropagation()}>
      {variante === 'icones' ? (
        <span className="inline-flex items-center gap-1">
          <IconeBouton libelle={`Modifier ${indicateur.code}`} onClick={() => setEdition(true)}>
            <IconeModifier />
          </IconeBouton>
          <IconeBouton
            libelle={`Supprimer ${indicateur.code}`}
            onClick={() => void supprimer()}
            disabled={suppressionEnCours}
            classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
          >
            <IconeSupprimer />
          </IconeBouton>
        </span>
      ) : (
        <span className="flex gap-2">
          <Bouton variante="secondaire" taille="compacte" icone={<IconeModifier />} onClick={() => setEdition(true)}>
            Modifier
          </Bouton>
          <Bouton variante="danger" taille="compacte" icone={<IconeSupprimer />} disabled={suppressionEnCours} onClick={() => void supprimer()}>
            Supprimer
          </Bouton>
        </span>
      )}

      {edition && (
        <ModifierIndicateurModale indicateur={indicateur} composantes={composantes?.donnees ?? []} onFermer={() => setEdition(false)} />
      )}
    </span>
  )
}
