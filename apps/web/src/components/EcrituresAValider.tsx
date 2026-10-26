import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import {
  ecrituresAValiderQueryOptions,
  NATURES_ENGAGEMENT,
  validerDepensesEnLot,
  validerEngagementsEnLot,
  type ResultatLot,
} from '@/lib/budget'
import { formaterDate, formaterFcfa } from '@/lib/format'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { usePeut, useUtilisateurCourant } from '@/lib/use-permissions'
import { useToast } from './ToastProvider'
import { Bouton } from './Bouton'
import { IconeValider } from './Icones'

const LIBELLES_NATURE: Record<string, string> = Object.fromEntries(NATURES_ENGAGEMENT.map((n) => [n.valeur, n.libelle]))

type Onglet = 'engagements' | 'depenses'

interface LigneAValider {
  uuid: string
  reference: string
  detail: string
  activite: { uuid: string; code: string }
  ligne: string
  montant: number
  date: string
  saisiPar: { uuid: string; nomComplet: string }
}

/**
 * Engagements et dépenses en brouillon, à valider en lot — après un import
 * surtout. Les écritures saisies par la personne connectée sont affichées
 * mais non cochables : le serveur refuserait qu'elle les valide (§ 7.2).
 * Chaque écriture passe les mêmes contrôles que la validation unitaire ;
 * celles qui sont refusées le sont avec leur motif.
 */
export function EcrituresAValider() {
  const peutValiderEngagements = usePeut('budget.engagement.valider')
  const peutValiderDepenses = usePeut('budget.depense.valider')
  const { data: moi } = useUtilisateurCourant()
  const { data } = useQuery(ecrituresAValiderQueryOptions(peutValiderEngagements || peutValiderDepenses))
  const afficherToast = useToast()
  const [onglet, setOnglet] = useState<Onglet>(peutValiderEngagements ? 'engagements' : 'depenses')
  const [selection, setSelection] = useState<Set<string>>(new Set())
  const [enCours, setEnCours] = useState(false)
  const [resultat, setResultat] = useState<ResultatLot | null>(null)
  const [erreur, setErreur] = useState<string | null>(null)

  if (!data || (data.engagements.length === 0 && data.depenses.length === 0)) {
    return null
  }

  const lignes: LigneAValider[] =
    onglet === 'engagements'
      ? data.engagements.map((e) => ({
          uuid: e.uuid,
          reference: e.reference ?? '—',
          detail: LIBELLES_NATURE[e.nature] ?? e.nature,
          activite: e.activite,
          ligne: e.ligne,
          montant: e.montant,
          date: e.date,
          saisiPar: e.saisiPar,
        }))
      : data.depenses.map((d) => ({
          uuid: d.uuid,
          reference: d.referencePaiement ?? '—',
          detail: `Engagement ${d.engagement.reference ?? '—'}${d.engagement.statut === 'brouillon' ? ' (non validé)' : ''}`,
          activite: d.activite,
          ligne: d.ligne,
          montant: d.montant,
          date: d.date,
          saisiPar: d.saisiPar,
        }))
  const eligibles = lignes.filter((l) => l.saisiPar.uuid !== moi?.uuid)
  const toutesCochees = eligibles.length > 0 && eligibles.every((l) => selection.has(l.uuid))

  function changerOnglet(nouveau: Onglet) {
    setOnglet(nouveau)
    setSelection(new Set())
    setResultat(null)
  }

  function basculer(uuid: string) {
    setSelection((avant) => {
      const apres = new Set(avant)
      if (apres.has(uuid)) apres.delete(uuid)
      else apres.add(uuid)

      return apres
    })
  }

  async function valider() {
    setErreur(null)
    setResultat(null)
    setEnCours(true)
    try {
      const uuids = [...selection]
      const reponse = onglet === 'engagements' ? await validerEngagementsEnLot(uuids) : await validerDepensesEnLot(uuids)
      setResultat(reponse)
      setSelection(new Set())
      await Promise.all(
        [['budget'], ['arbre'], ['synthese'], ['decaissement'], ['execution'], ['execution-pluriannuelle'], ['alertes'], ['activite']].map((queryKey) =>
          queryClient.invalidateQueries({ queryKey }),
        ),
      )
      if (reponse.validees > 0) {
        afficherToast(`${reponse.validees} ${onglet === 'engagements' ? 'engagement(s)' : 'dépense(s)'} validé(s).`)
      }
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  const ongletsVisibles: { valeur: Onglet; libelle: string; nombre: number }[] = [
    ...(peutValiderEngagements ? [{ valeur: 'engagements' as const, libelle: 'Engagements', nombre: data.engagements.length }] : []),
    ...(peutValiderDepenses ? [{ valeur: 'depenses' as const, libelle: 'Dépenses', nombre: data.depenses.length }] : []),
  ]

  return (
    <section>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Écritures à valider</h2>
          <p className="text-sm text-muted">
            En brouillon, saisies ou importées. Le plafond est contrôlé à la validation ; vos propres saisies sont validées par une autre personne.
          </p>
        </div>
        <div className="flex gap-0.5 rounded-[var(--radius-controle)] bg-nested p-0.5 text-sm" role="group" aria-label="Type d'écriture">
          {ongletsVisibles.map((o) => (
            <button
              key={o.valeur}
              type="button"
              aria-pressed={onglet === o.valeur}
              onClick={() => changerOnglet(o.valeur)}
              className={`rounded-[calc(var(--radius-controle)-2px)] px-3 py-1.5 font-semibold transition-colors ${
                onglet === o.valeur ? 'bg-accent text-white' : 'text-muted hover:text-accent'
              }`}
            >
              {o.libelle} <span className="chiffres">({o.nombre})</span>
            </button>
          ))}
        </div>
      </div>

      {erreur && (
        <div role="alert" className="mb-3 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}
      {resultat && resultat.refus.length > 0 && (
        <div role="status" className="mb-3 rounded-[var(--radius-controle)] bg-warn-bg px-4 py-3 text-sm text-warn">
          <p className="font-semibold">
            {resultat.validees} validé(s), {resultat.refus.length} refusé(s) :
          </p>
          <ul className="mt-1 list-disc pl-5">
            {resultat.refus.map((r) => (
              <li key={r.uuid}>
                <span className="chiffres">{r.reference ?? '—'}</span> — {r.motif}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
        {lignes.length === 0 ? (
          <p className="px-4 py-3 text-sm text-muted">Rien à valider.</p>
        ) : (
          <table className="w-full min-w-[52rem] border-collapse text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted">
                <th className="w-10 px-4 py-3">
                  <input
                    type="checkbox"
                    aria-label="Tout sélectionner"
                    checked={toutesCochees}
                    disabled={eligibles.length === 0}
                    onChange={() => setSelection(toutesCochees ? new Set() : new Set(eligibles.map((l) => l.uuid)))}
                  />
                </th>
                <th className="px-4 py-3 font-semibold">Référence</th>
                <th className="px-4 py-3 font-semibold">{onglet === 'engagements' ? 'Nature' : 'Engagement'}</th>
                <th className="px-4 py-3 font-semibold">Ligne budgétaire</th>
                <th className="px-4 py-3 font-semibold">Date</th>
                <th className="px-4 py-3 text-right font-semibold">Montant</th>
                <th className="px-4 py-3 font-semibold">Saisi par</th>
              </tr>
            </thead>
            <tbody>
              {lignes.map((l) => {
                const sienne = l.saisiPar.uuid === moi?.uuid

                return (
                  <tr key={l.uuid} className={`border-b border-border last:border-0 ${sienne ? 'text-muted' : 'hover:bg-hover'}`}>
                    <td className="px-4 py-2.5">
                      <input
                        type="checkbox"
                        aria-label={`Sélectionner ${l.reference}`}
                        checked={selection.has(l.uuid)}
                        disabled={sienne}
                        title={sienne ? 'Saisie par vous : une autre personne la valide.' : undefined}
                        onChange={() => basculer(l.uuid)}
                      />
                    </td>
                    <td className="chiffres px-4 py-2.5">{l.reference}</td>
                    <td className="px-4 py-2.5">{l.detail}</td>
                    <td className="max-w-[22rem] px-4 py-2.5">
                      <Link to={`/composantes/activites/${l.activite.uuid}`} className="block truncate text-accent hover:underline" title={l.ligne}>
                        <span className="chiffres mr-1.5">{l.activite.code}</span>
                        {l.ligne}
                      </Link>
                    </td>
                    <td className="chiffres px-4 py-2.5">{formaterDate(l.date)}</td>
                    <td className="chiffres px-4 py-2.5 text-right">{formaterFcfa(l.montant)}</td>
                    <td className="px-4 py-2.5">{sienne ? 'Vous' : l.saisiPar.nomComplet}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-muted">
          {data.engagements.length >= data.limite || data.depenses.length >= data.limite
            ? `Les ${data.limite} plus anciennes sont affichées : validez-les, les suivantes apparaîtront.`
            : `${eligibles.length} écriture(s) que vous pouvez valider.`}
        </p>
        <Bouton icone={<IconeValider />} disabled={enCours || selection.size === 0} onClick={() => void valider()}>
          {enCours ? 'Validation…' : `Valider la sélection (${selection.size})`}
        </Bouton>
      </div>
    </section>
  )
}
