import { Fragment, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { journalQueryOptions } from '@/lib/admin'
import { formaterDate } from '@/lib/format'
import { AdminOnglets } from '@/components/AdminOnglets'
import { ErreurChargement } from '@/components/ErreurChargement'
import { Squelette } from '@/components/Squelette'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'

const TYPES_ENTITE = [
  'activite',
  'dotation',
  'engagement',
  'depense',
  'indicateur',
  'indicateur_valeur',
  'structure',
  'role',
  'permission',
  'utilisateur',
  'personnel',
  'contrat',
  'effectif_enqueteurs',
  'point_focal',
]

const LIBELLES_ACTION: Record<string, string> = {
  creation: 'Création',
  modification: 'Modification',
  suppression: 'Suppression',
}

export function AdminJournal() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [ligneOuverte, setLigneOuverte] = useState<string | null>(null)

  const entite = searchParams.get('entite') ?? undefined
  const du = searchParams.get('du') ?? undefined
  const au = searchParams.get('au') ?? undefined
  const page = Number(searchParams.get('page') ?? '1')

  const { data: journal, isLoading, isError, error } = useQuery(journalQueryOptions({ entite, du, au, page }))

  function definirFiltre(cle: string, valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') suivant.delete(cle)
    else suivant.set(cle, valeur)
    suivant.delete('page')
    setSearchParams(suivant, { replace: true })
  }

  function changerPage(suivante: number) {
    const params = new URLSearchParams(searchParams)
    params.set('page', String(suivante))
    setSearchParams(params, { replace: true })
  }

  return (
    <div>
      <p className="font-titre text-eyebrow uppercase tracking-wide">Backoffice</p>
      <h1 className="text-2xl">Administration</h1>
      <p className="mb-6 text-muted">Référentiels, parties prenantes, rôles et permissions, journal des actions.</p>

      <AdminOnglets />

      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">Journal d'activité</h2>
        <BoutonExporter href={urlExportDonnees('journal', { entite, du, au })} libelle="Exporter" />
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-3" role="search" aria-label="Filtres du journal">
        <label className="flex flex-col gap-1 text-sm">
          <span className="text-xs uppercase tracking-wide text-muted">Entité</span>
          <select
            value={entite ?? ''}
            onChange={(e) => definirFiltre('entite', e.target.value)}
            className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2"
          >
            <option value="">Toutes</option>
            {TYPES_ENTITE.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1 text-sm">
          <span className="text-xs uppercase tracking-wide text-muted">Du</span>
          <input
            type="date"
            value={du ?? ''}
            onChange={(e) => definirFiltre('du', e.target.value)}
            className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2"
          />
        </label>
        <label className="flex flex-col gap-1 text-sm">
          <span className="text-xs uppercase tracking-wide text-muted">Au</span>
          <input
            type="date"
            value={au ?? ''}
            onChange={(e) => definirFiltre('au', e.target.value)}
            className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2"
          />
        </label>
      </div>

      {isLoading && <Squelette lignes={4} />}
      {isError && <ErreurChargement erreur={error} quoi="le journal" />}

      {journal && (
        <>
          <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-muted">
                  <th className="px-4 py-3 font-normal">Date</th>
                  <th className="px-4 py-3 font-normal">Utilisateur</th>
                  <th className="px-4 py-3 font-normal">Action</th>
                  <th className="px-4 py-3 font-normal">Entité</th>
                </tr>
              </thead>
              <tbody>
                {journal.donnees.map((entree) => (
                  <Fragment key={entree.id}>
                    <tr
                      className="cursor-pointer border-b border-border last:border-0 hover:bg-hover"
                      onClick={() => setLigneOuverte(ligneOuverte === entree.id ? null : entree.id)}
                    >
                      <td className="chiffres px-4 py-3 text-muted">{formaterDate(entree.creeLe)}</td>
                      <td className="px-4 py-3">{entree.utilisateur ?? '—'}</td>
                      <td className="px-4 py-3">{LIBELLES_ACTION[entree.action] ?? entree.action}</td>
                      <td className="px-4 py-3">
                        {entree.entiteType}
                        {entree.entiteId !== null && <span className="chiffres ml-1 text-muted">#{entree.entiteId}</span>}
                      </td>
                    </tr>
                    {ligneOuverte === entree.id && (
                      <tr className="border-b border-border bg-nested last:border-0">
                        <td colSpan={4} className="px-4 py-3">
                          <div className="grid grid-cols-2 gap-4 text-xs">
                            <div>
                              <p className="mb-1 font-semibold uppercase tracking-wide text-muted">Avant</p>
                              <pre className="chiffres overflow-x-auto whitespace-pre-wrap">
                                {entree.avant ? JSON.stringify(entree.avant, null, 2) : '—'}
                              </pre>
                            </div>
                            <div>
                              <p className="mb-1 font-semibold uppercase tracking-wide text-muted">Après</p>
                              <pre className="chiffres overflow-x-auto whitespace-pre-wrap">
                                {entree.apres ? JSON.stringify(entree.apres, null, 2) : '—'}
                              </pre>
                            </div>
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
                {journal.donnees.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-center text-muted">
                      Aucune écriture ne correspond à ces filtres.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {journal.pagination.pages > 1 && (
            <div className="mt-3 flex items-center justify-between text-sm text-muted">
              <span>
                Page {journal.pagination.page} / {journal.pagination.pages} · {journal.pagination.total} entrées
              </span>
              <div className="flex gap-2">
                <button
                  type="button"
                  disabled={page <= 1}
                  onClick={() => changerPage(page - 1)}
                  className="rounded-[var(--radius-controle)] border border-border px-3 py-1.5 disabled:opacity-40"
                >
                  Précédent
                </button>
                <button
                  type="button"
                  disabled={page >= journal.pagination.pages}
                  onClick={() => changerPage(page + 1)}
                  className="rounded-[var(--radius-controle)] border border-border px-3 py-1.5 disabled:opacity-40"
                >
                  Suivant
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
