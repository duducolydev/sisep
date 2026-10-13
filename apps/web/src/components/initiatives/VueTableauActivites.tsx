import { useState } from 'react'
import { useNavigate } from 'react-router'
import type { ActiviteAvecProjet, Initiative } from '@/lib/initiatives'
import { PastilleStatutInitiative } from '@/components/PastilleStatutInitiative'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { Pagination } from '@/components/Pagination'
import { trancherPage } from '@/lib/pagination'
import { formaterDate } from '@/lib/format'

const TAILLE_PAGE = 15

interface VueTableauActivitesProps {
  activitesFiltrees: ActiviteAvecProjet[]
  /** Projets retenus qui n'ont encore aucune activité — une ligne chacun, sinon ils n'apparaîtraient nulle part. */
  projetsSansActivite: Initiative[]
}

/** Une ligne de tableau : une activité, ou un projet qui n'en a encore aucune. */
type Ligne = { type: 'activite'; activite: ActiviteAvecProjet } | { type: 'projet'; projet: Initiative }

/**
 * Une ligne par activité, tous projets confondus — même motif de tableau que
 * AdminUtilisateurs.tsx.
 *
 * Un projet sans activité y a sa propre ligne, en tête : un tableau qui ne
 * liste que des activités rendait un projet tout juste créé invisible, et
 * donc impossible à ouvrir pour lui en ajouter une.
 */
export function VueTableauActivites({ activitesFiltrees, projetsSansActivite }: VueTableauActivitesProps) {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)

  const lignes: Ligne[] = [
    ...projetsSansActivite.map((projet): Ligne => ({ type: 'projet', projet })),
    ...activitesFiltrees.map((activite): Ligne => ({ type: 'activite', activite })),
  ]

  const totalPages = Math.max(1, Math.ceil(lignes.length / TAILLE_PAGE))
  const lignesPage = trancherPage(lignes, page, TAILLE_PAGE)

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
          {lignesPage.map((ligne) =>
            ligne.type === 'projet' ? (
              <tr
                key={`projet-${ligne.projet.uuid}`}
                onClick={() => void navigate(`/initiatives/${ligne.projet.uuid}`)}
                className="cursor-pointer border-b border-border last:border-0 hover:bg-hover"
              >
                <td className="px-4 py-3">
                  <span className="font-semibold">{ligne.projet.libelle}</span>
                  {ligne.projet.lienPtba && <p className="text-xs text-muted">{ligne.projet.lienPtba.code}</p>}
                </td>
                <td colSpan={5} className="px-4 py-3 text-muted italic">
                  Aucune activité — ouvrir le projet pour en ajouter
                </td>
              </tr>
            ) : (
              <tr
                key={ligne.activite.uuid}
                onClick={() => void navigate(`/initiatives/${ligne.activite.initiativeUuid}`)}
                className="cursor-pointer border-b border-border last:border-0 hover:bg-hover"
              >
                <td className="px-4 py-3">
                  <span className="font-semibold">{ligne.activite.initiativeLibelle}</span>
                  {ligne.activite.initiativeLienPtba && (
                    <p className="text-xs text-muted">{ligne.activite.initiativeLienPtba.code}</p>
                  )}
                </td>
                <td className="px-4 py-3">{ligne.activite.libelle}</td>
                <td className="px-4 py-3 text-muted">
                  {ligne.activite.responsables.length > 0
                    ? ligne.activite.responsables.map((r) => r.nomComplet).join(', ')
                    : '—'}
                </td>
                <td className="chiffres px-4 py-3 text-muted">
                  {ligne.activite.dateDebut ? formaterDate(ligne.activite.dateDebut) : '—'} →{' '}
                  {ligne.activite.echeance ? formaterDate(ligne.activite.echeance) : '—'}
                </td>
                <td className="px-4 py-3">
                  <PastilleStatutInitiative statut={ligne.activite.statut} />
                </td>
                <td className="px-4 py-3">
                  <BarreAtteinte taux={ligne.activite.avancement} />
                </td>
              </tr>
            ),
          )}
          {lignesPage.length === 0 && (
            <tr>
              <td colSpan={6} className="px-4 py-6 text-center text-muted">
                Aucun projet ni aucune activité ne correspond à ces filtres.
              </td>
            </tr>
          )}
        </tbody>
      </table>
      <Pagination page={page} totalPages={totalPages} total={lignes.length} taille={TAILLE_PAGE} onChangerPage={setPage} />
    </div>
  )
}
