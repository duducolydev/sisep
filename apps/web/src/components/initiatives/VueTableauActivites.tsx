import { useState } from 'react'
import { useNavigate } from 'react-router'
import type { ActiviteAvecProjet } from '@/lib/initiatives'
import { PastilleStatutInitiative } from '@/components/PastilleStatutInitiative'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { Pagination } from '@/components/Pagination'
import { trancherPage } from '@/lib/pagination'
import { formaterDate } from '@/lib/format'

const TAILLE_PAGE = 15

interface VueTableauActivitesProps {
  activitesFiltrees: ActiviteAvecProjet[]
}

/** Une ligne par activité, tous projets confondus — même motif de tableau que AdminUtilisateurs.tsx. */
export function VueTableauActivites({ activitesFiltrees }: VueTableauActivitesProps) {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)

  const totalPages = Math.max(1, Math.ceil(activitesFiltrees.length / TAILLE_PAGE))
  const activitesPage = trancherPage(activitesFiltrees, page, TAILLE_PAGE)

  return (
    <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border text-left text-muted">
            <th className="px-4 py-3 font-normal">Projet</th>
            <th className="px-4 py-3 font-normal">Activité</th>
            <th className="px-4 py-3 font-normal">Responsable(s)</th>
            <th className="px-4 py-3 font-normal">Début → Échéance</th>
            <th className="px-4 py-3 font-normal">Statut</th>
            <th className="px-4 py-3 font-normal">Avancement</th>
          </tr>
        </thead>
        <tbody>
          {activitesPage.map((a) => (
            <tr
              key={a.uuid}
              onClick={() => void navigate(`/initiatives/${a.initiativeUuid}`)}
              className="cursor-pointer border-b border-border last:border-0 hover:bg-hover"
            >
              <td className="px-4 py-3">
                <span className="font-semibold">{a.initiativeLibelle}</span>
                {a.initiativeLienPtba && <p className="text-xs text-muted">{a.initiativeLienPtba.code}</p>}
              </td>
              <td className="px-4 py-3">{a.libelle}</td>
              <td className="px-4 py-3 text-muted">
                {a.responsables.length > 0 ? a.responsables.map((r) => r.nomComplet).join(', ') : '—'}
              </td>
              <td className="chiffres px-4 py-3 text-muted">
                {a.dateDebut ? formaterDate(a.dateDebut) : '—'} → {a.echeance ? formaterDate(a.echeance) : '—'}
              </td>
              <td className="px-4 py-3">
                <PastilleStatutInitiative statut={a.statut} />
              </td>
              <td className="px-4 py-3">
                <BarreAtteinte taux={a.avancement} />
              </td>
            </tr>
          ))}
          {activitesPage.length === 0 && (
            <tr>
              <td colSpan={6} className="px-4 py-6 text-center text-muted">
                Aucune activité ne correspond à ces filtres.
              </td>
            </tr>
          )}
        </tbody>
      </table>
      <Pagination page={page} totalPages={totalPages} total={activitesFiltrees.length} taille={TAILLE_PAGE} onChangerPage={setPage} />
    </div>
  )
}
