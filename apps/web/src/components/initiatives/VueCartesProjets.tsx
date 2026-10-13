import { useState } from 'react'
import { Link } from 'react-router'
import type { Initiative } from '@/lib/initiatives'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { Pagination } from '@/components/Pagination'
import { trancherPage } from '@/lib/pagination'

const TAILLE_PAGE = 9

interface VueCartesProjetsProps {
  /** Projets déjà filtrés par la page — y compris ceux qui n'ont encore aucune activité. */
  projets: Initiative[]
  /** Vrai quand il n'existe aucun projet du tout, pour ne pas parler de filtres qui n'existent pas. */
  aucunProjet: boolean
}

/**
 * Grille de cartes par projet. Le filtrage appartient à la page, qui est
 * seule à connaître la règle : un projet vide reste affiché tant qu'aucun
 * filtre ne porte sur les activités. La barre de progression est calculée
 * sur toutes les activités du projet, pas sur celles retenues par le filtre.
 */
export function VueCartesProjets({ projets, aucunProjet }: VueCartesProjetsProps) {
  const [page, setPage] = useState(1)

  const totalPages = Math.max(1, Math.ceil(projets.length / TAILLE_PAGE))
  const projetsPage = trancherPage(projets, page, TAILLE_PAGE)

  if (projets.length === 0) {
    return (
      <p className="text-muted">
        {aucunProjet ? 'Aucun projet pour le moment.' : 'Aucun projet ne correspond à ces filtres.'}
      </p>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {projetsPage.map((i) => {
          const avancements = i.activites.map((a) => a.avancement).filter((a): a is number => a !== null)
          const tauxMoyen = avancements.length > 0 ? avancements.reduce((s, a) => s + a, 0) / avancements.length : null

          return (
            <Link
              key={i.uuid}
              to={`/initiatives/${i.uuid}`}
              className="rounded-[var(--radius-carte)] border border-border bg-surface p-4 shadow-[var(--shadow-carte)] transition-colors hover:border-accent"
            >
              <p className="text-[10px] font-semibold uppercase tracking-[.14em] text-eyebrow">
                {i.lienPtba ? `${i.lienPtba.code} — ${i.lienPtba.libelle}` : 'Indépendant du PTBA'}
              </p>
              <p className="font-titre text-base">{i.libelle}</p>
              {i.description && <p className="mt-1 text-sm text-muted line-clamp-2">{i.description}</p>}
              <div className="mt-3 border-t border-border pt-3">
                <BarreAtteinte taux={tauxMoyen} />
                <p className="mt-1 text-xs text-muted">
                  {i.activites.length === 0
                    ? 'Aucune activité — ouvrir le projet pour en ajouter'
                    : `${i.activites.length} activité${i.activites.length > 1 ? 's' : ''}`}
                </p>
              </div>
            </Link>
          )
        })}
      </div>
      <Pagination page={page} totalPages={totalPages} total={projets.length} taille={TAILLE_PAGE} onChangerPage={setPage} />
    </div>
  )
}
