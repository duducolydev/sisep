import { detientGlobalement } from '@/lib/auth'
import { useUtilisateurCourant } from '@/lib/use-permissions'

/**
 * Le serveur limite les montants au périmètre de l'utilisateur : sans cette
 * mention, un point focal lirait le total de ses activités comme celui du projet.
 */
export function MentionPerimetre() {
  const { data: moi } = useUtilisateurCourant()

  if (!moi || detientGlobalement(moi, 'activites.consulter')) {
    return null
  }

  return <p className="text-sm text-muted">Les montants portent sur les activités de votre périmètre, pas sur tout le projet.</p>
}
