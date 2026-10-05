import { useState } from 'react'
import { Link } from 'react-router'
import type { ActiviteAvecProjet, Initiative } from '@/lib/initiatives'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { Pagination } from '@/components/Pagination'
import { trancherPage } from '@/lib/pagination'

const TAILLE_PAGE = 9

interface VueCartesProjetsProps {
  projets: Initiative[]
  activitesFiltrees: ActiviteAvecProjet[]
}

/** Grille de cartes par projet (vue historique) — un projet est affiché dès qu'il a au moins une activité correspondant aux filtres, mais sa barre de progression reste calculée sur toutes ses activités (progression réelle, pas tronquée par le filtre). */
export function VueCartesProjets({ projets, activitesFiltrees }: VueCartesProjetsProps) {
  const [page, setPage] = useState(1)

  const initiativeUuidsFiltrees = new Set(activitesFiltrees.map((a) => a.initiativeUuid))
  const projetsFiltres = projets.filter((i) => initiativeUuidsFiltrees.has(i.uuid))

  const totalPages = Math.max(1, Math.ceil(projetsFiltres.length / TAILLE_PAGE))
  const projetsPage = trancherPage(projetsFiltres, page, TAILLE_PAGE)

  if (projetsFiltres.length === 0) {
    return <p className="text-muted">Aucun projet ne correspond à ces filtres.</p>
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
                  {i.activites.length} activité{i.activites.length > 1 ? 's' : ''}
                </p>
              </div>
            </Link>
          )
        })}
      </div>
      <Pagination page={page} totalPages={totalPages} total={projetsFiltres.length} taille={TAILLE_PAGE} onChangerPage={setPage} />
    </div>
  )
}
