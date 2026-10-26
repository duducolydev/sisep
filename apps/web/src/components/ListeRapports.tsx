import { useRef, useState } from 'react'
import { transmettreRapport, type Rapport } from '@/lib/parties-prenantes'
import { queryClient } from '@/lib/query-client'
import { formaterDate } from '@/lib/format'
import { Pastille } from './Pastille'
import { Bouton } from './Bouton'
import { IconeTransmettre } from './Icones'
import { useToast } from './ToastProvider'

const LIBELLES_STATUT_RAPPORT: Record<Rapport['statutAffiche'], string> = {
  attendu: 'Attendu',
  transmis: 'Transmis',
  valide: 'Validé',
  rejete: 'Rejeté',
  retard: 'En retard',
}

interface ListeRapportsProps {
  rapports: Rapport[]
  /** Droit de transmettre ce rapport — hint d'affichage, revérifié côté serveur. */
  peutTransmettre: (rapport: Rapport) => boolean
  /** Affiche le sigle de la structure sur chaque ligne (plusieurs structures listées). */
  avecStructure?: boolean
}

/**
 * Rapports périodiques d'une ou plusieurs structures, avec la transmission
 * du fichier pour ceux qui sont attendus — commune à la fiche d'une partie
 * prenante et à l'écran « Mes rapports » des points focaux.
 */
export function ListeRapports({ rapports, peutTransmettre, avecStructure = false }: ListeRapportsProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  if (rapports.length === 0) {
    return <p className="text-muted">Aucun rapport pour le moment.</p>
  }

  return (
    <>
      {erreur && (
        <div role="alert" className="mb-3 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}
      <ul className="flex flex-col gap-2">
        {rapports.map((r) => (
          <LigneRapport key={r.uuid} rapport={r} peutTransmettre={peutTransmettre(r)} avecStructure={avecStructure} onErreur={setErreur} />
        ))}
      </ul>
    </>
  )
}

/**
 * Une ligne, et son propre sélecteur de fichier : partagé entre les lignes,
 * il transmettait le fichier choisi sur le dernier rapport de la liste,
 * quel que soit le bouton cliqué.
 */
function LigneRapport({
  rapport,
  peutTransmettre,
  avecStructure,
  onErreur,
}: {
  rapport: Rapport
  peutTransmettre: boolean
  avecStructure: boolean
  onErreur: (message: string | null) => void
}) {
  const fichierRef = useRef<HTMLInputElement>(null)
  const [enCours, setEnCours] = useState(false)
  const afficherToast = useToast()

  async function transmettre(fichier: File) {
    onErreur(null)
    setEnCours(true)
    try {
      await transmettreRapport(rapport.uuid, fichier)
      await queryClient.invalidateQueries({ queryKey: ['rapports'] })
      afficherToast(`Rapport ${rapport.periode} ${rapport.exercice} transmis.`)
    } catch (e) {
      onErreur(e instanceof Error ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
      <div>
        {avecStructure && <span className="mr-2 font-semibold">{rapport.structure.sigle}</span>}
        <span className="chiffres mr-2 text-muted">
          {rapport.periode} {rapport.exercice}
        </span>
        <span className="text-sm text-muted">Échéance {formaterDate(rapport.dateEcheance)}</span>
      </div>
      <div className="flex items-center gap-3">
        <Pastille statut={rapport.statutAffiche === 'retard' ? 'annule' : rapport.statut} />
        <span className="text-xs text-muted">{LIBELLES_STATUT_RAPPORT[rapport.statutAffiche]}</span>
        {rapport.pieceJointe && (
          <span className="text-xs text-muted" title={rapport.pieceJointe.nomFichier}>
            📎 {rapport.pieceJointe.nomFichier}
          </span>
        )}
        {rapport.statut === 'attendu' && peutTransmettre && (
          <>
            <input
              ref={fichierRef}
              type="file"
              className="hidden"
              aria-label={`Fichier du rapport ${rapport.periode} ${rapport.exercice}`}
              onChange={(e) => {
                const fichier = e.target.files?.[0]
                if (fichier) void transmettre(fichier)
                e.target.value = ''
              }}
            />
            <Bouton variante="discret" taille="mini" icone={<IconeTransmettre />} disabled={enCours} onClick={() => fichierRef.current?.click()}>
              {enCours ? 'Envoi…' : 'Transmettre'}
            </Bouton>
          </>
        )}
      </div>
    </li>
  )
}
