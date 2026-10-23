import { useState } from 'react'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { creerPointFocal, modifierPointFocal, type PointFocal } from '@/lib/parties-prenantes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface PointFocalModaleProps {
  structureUuid: string
  structureSigle: string
  /** Absent : création ; présent : modification. */
  pointFocal?: PointFocal
  onFermer: () => void
}

/** Ajouter ou modifier un point focal directement depuis la fiche de sa structure. */
export function PointFocalModale({ structureUuid, structureSigle, pointFocal, onFermer }: PointFocalModaleProps) {
  const [nomComplet, setNomComplet] = useState(pointFocal?.nomComplet ?? '')
  const [fonction, setFonction] = useState(pointFocal?.fonction ?? '')
  const [telephone, setTelephone] = useState(pointFocal?.telephone ?? '')
  const [email, setEmail] = useState(pointFocal?.email ?? '')
  const [principal, setPrincipal] = useState(pointFocal?.principal ?? false)
  const [dateDesignation, setDateDesignation] = useState(pointFocal?.dateDesignation?.slice(0, 10) ?? '')
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  async function enregistrer() {
    if (nomComplet.trim() === '' || fonction.trim() === '') {
      setErreur('Le prénom et nom, et la fonction, sont requis.')

      return
    }
    if (email.trim() !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setErreur('Adresse e-mail invalide.')

      return
    }

    setErreur(null)
    setEnCours(true)
    const champs = {
      nomComplet: nomComplet.trim(),
      fonction: fonction.trim(),
      telephone: telephone.trim() || undefined,
      email: email.trim() || undefined,
      principal,
      dateDesignation: dateDesignation || undefined,
    }
    try {
      if (pointFocal) {
        await modifierPointFocal(pointFocal.uuid, {
          ...champs,
          telephone: telephone.trim() || null,
          email: email.trim() || null,
          dateDesignation: dateDesignation || null,
        })
      } else {
        await creerPointFocal({ structure: structureUuid, ...champs })
      }
      await queryClient.invalidateQueries({ queryKey: ['points-focaux'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  const champ = 'rounded-[var(--radius-controle)] border border-border px-3 py-2'

  return (
    <Modale titre={pointFocal ? `Modifier ${pointFocal.nomComplet}` : `Ajouter un point focal — ${structureSigle}`} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="pf-nom">Prénom et nom</label>
          <input id="pf-nom" value={nomComplet} onChange={(e) => setNomComplet(e.target.value)} className={champ} />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="pf-fonction">Fonction</label>
          <input id="pf-fonction" value={fonction} onChange={(e) => setFonction(e.target.value)} className={champ} />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1">
            <label htmlFor="pf-telephone">Téléphone (optionnel)</label>
            <input id="pf-telephone" value={telephone} onChange={(e) => setTelephone(e.target.value)} className={`chiffres ${champ}`} />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="pf-email">E-mail (optionnel)</label>
            <input id="pf-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} className={champ} />
          </div>
        </div>
        <div className="grid grid-cols-1 items-end gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1">
            <label htmlFor="pf-designation">Date de désignation (optionnel)</label>
            <input id="pf-designation" type="date" value={dateDesignation} onChange={(e) => setDateDesignation(e.target.value)} className={`chiffres ${champ}`} />
          </div>
          <label className="flex items-center gap-2 pb-2">
            <input type="checkbox" checked={principal} onChange={(e) => setPrincipal(e.target.checked)} />
            Point focal principal
          </label>
        </div>
        <p className="text-xs text-muted">
          L'e-mail reçoit les relances de reporting. Pour donner accès à la plateforme, liez ensuite un compte à ce point focal.
        </p>
      </div>
      <div className="mt-5 flex justify-end gap-3 border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Annuler
        </Bouton>
        <Bouton disabled={enCours} onClick={() => void enregistrer()}>
          {enCours ? 'Enregistrement…' : pointFocal ? 'Enregistrer' : 'Ajouter'}
        </Bouton>
      </div>
    </Modale>
  )
}
