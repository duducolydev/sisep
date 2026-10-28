import { useState } from 'react'
import type { Rapport } from '@/lib/parties-prenantes'
import { urlTelechargerDocument } from '@/lib/documents'
import { formaterDate } from '@/lib/format'
import { Pastille } from './Pastille'
import { Bouton } from './Bouton'
import { IconeTransmettre } from './Icones'
import { TransmettreRapportModale } from './TransmettreRapportModale'

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
 * du fichier, après aperçu, pour ceux qui sont attendus — commune à la fiche d'une partie
 * prenante et à l'écran « Mes rapports » des points focaux.
 */
export function ListeRapports({ rapports, peutTransmettre, avecStructure = false }: ListeRapportsProps) {
  if (rapports.length === 0) {
    return <p className="text-muted">Aucun rapport pour le moment.</p>
  }

  return (
    <ul className="flex flex-col gap-2">
      {rapports.map((r) => (
        <LigneRapport key={r.uuid} rapport={r} peutTransmettre={peutTransmettre(r)} avecStructure={avecStructure} />
      ))}
    </ul>
  )
}

/**
 * Une ligne de rapport : le fichier transmis s'ouvre dans le navigateur
 * (« Voir »), et la transmission passe par un aperçu du fichier choisi
 * (TransmettreRapportModale) avant tout envoi.
 */
function LigneRapport({ rapport, peutTransmettre, avecStructure }: { rapport: Rapport; peutTransmettre: boolean; avecStructure: boolean }) {
  const [transmission, setTransmission] = useState(false)

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
          <a
            href={`${urlTelechargerDocument(rapport.pieceJointe.uuid)}?apercu=1`}
            target="_blank"
            rel="noreferrer"
            title={`Voir ${rapport.pieceJointe.nomFichier} (un PDF s'ouvre dans le navigateur, les autres formats se téléchargent)`}
            className="max-w-[16rem] truncate text-xs font-semibold text-accent hover:underline"
          >
            📎 Voir {rapport.pieceJointe.nomFichier}
          </a>
        )}
        {rapport.statut === 'attendu' && peutTransmettre && (
          <Bouton variante="discret" taille="mini" icone={<IconeTransmettre />} onClick={() => setTransmission(true)}>
            Transmettre
          </Bouton>
        )}
      </div>
      {transmission && <TransmettreRapportModale rapport={rapport} onFermer={() => setTransmission(false)} />}
    </li>
  )
}
