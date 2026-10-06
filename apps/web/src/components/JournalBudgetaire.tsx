import { Fragment, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { journalBudgetQueryOptions, type EntreeJournalBudget } from '@/lib/admin'
import { formaterDate, formaterFcfa } from '@/lib/format'
import { Squelette } from '@/components/Squelette'
import { Bouton } from '@/components/Bouton'
import { IconeVoir } from '@/components/Icones'

const TYPES = [
  { valeur: '', libelle: 'Toutes les écritures' },
  { valeur: 'dotation', libelle: 'Dotations' },
  { valeur: 'engagement', libelle: 'Engagements' },
  { valeur: 'depense', libelle: 'Dépenses' },
]

const LIBELLES_ACTION: Record<string, string> = {
  creation: 'Saisie',
  modification: 'Modification',
  suppression: 'Suppression',
}

const LIBELLES_TYPE: Record<string, string> = {
  dotation: 'Dotation',
  engagement: 'Engagement',
  depense: 'Dépense',
}

/**
 * Fil des écritures budgétaires : qui a saisi, modifié ou validé quoi, et
 * quand.
 *
 * Il vit sur l'écran du budget et pas seulement dans l'onglet
 * d'administration, parce que c'est la contrepartie de la dérogation qui
 * laisse le responsable suivi-évaluation saisir et valider les mêmes
 * écritures (§ 7.2) : une traçabilité qu'il faut aller chercher ailleurs ne
 * compense rien.
 *
 * Une validation n'est pas une action distincte dans le journal : c'est une
 * modification du statut. Le détail de la ligne la montre telle quelle,
 * champ par champ, plutôt que de la renommer et de laisser croire à une
 * nature d'écriture qui n'existe pas en base.
 */
export function JournalBudgetaire() {
  const [type, setType] = useState('')
  const [du, setDu] = useState('')
  const [au, setAu] = useState('')
  const [page, setPage] = useState(1)
  const [ligneOuverte, setLigneOuverte] = useState<string | null>(null)

  const { data, isLoading, isError } = useQuery(
    journalBudgetQueryOptions({
      type: type === '' ? undefined : type,
      du: du === '' ? undefined : du,
      au: au === '' ? undefined : au,
      page,
    }),
  )

  function changerFiltre(action: () => void) {
    action()
    setPage(1)
  }

  return (
    <section className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
      <h2 className="text-base font-semibold">Journal des écritures budgétaires</h2>
      <p className="mb-3 text-xs text-muted">
        Qui a saisi, modifié ou validé une dotation, un engagement ou une dépense — avec l'état avant et après.
      </p>

      <div className="mb-3 flex flex-wrap gap-3">
        <select
          aria-label="Type d'écriture"
          value={type}
          onChange={(e) => changerFiltre(() => setType(e.target.value))}
          className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-sm"
        >
          {TYPES.map((t) => (
            <option key={t.valeur} value={t.valeur}>
              {t.libelle}
            </option>
          ))}
        </select>
        <label className="flex items-center gap-2 text-sm text-muted">
          Du
          <input
            type="date"
            value={du}
            onChange={(e) => changerFiltre(() => setDu(e.target.value))}
            className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-text"
          />
        </label>
        <label className="flex items-center gap-2 text-sm text-muted">
          au
          <input
            type="date"
            value={au}
            onChange={(e) => changerFiltre(() => setAu(e.target.value))}
            className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-text"
          />
        </label>
      </div>

      {isLoading && <Squelette lignes={3} />}
      {isError && <p className="text-bad">Impossible de charger le journal. Réessayez.</p>}

      {data && data.donnees.length === 0 && (
        <p className="py-6 text-center text-sm text-muted">Aucune écriture budgétaire sur cette période.</p>
      )}

      {data && data.donnees.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[52rem] border-collapse text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted">
                <th className="px-3 py-2 font-semibold">Date</th>
                <th className="px-3 py-2 font-semibold">Auteur</th>
                <th className="px-3 py-2 font-semibold">Action</th>
                <th className="px-3 py-2 font-semibold">Écriture</th>
                <th className="px-3 py-2 text-right font-semibold">Montant</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {data.donnees.map((entree) => (
                <Fragment key={entree.id}>
                  <tr className="border-b border-border last:border-0 hover:bg-hover">
                    <td className="chiffres px-3 py-2 whitespace-nowrap">{formaterDate(entree.creeLe)}</td>
                    <td className="px-3 py-2">{entree.utilisateur ?? 'Système'}</td>
                    <td className="px-3 py-2 text-muted">{LIBELLES_ACTION[entree.action] ?? entree.action}</td>
                    <td className="px-3 py-2">
                      <Reference entree={entree} />
                    </td>
                    <td className="chiffres px-3 py-2 text-right whitespace-nowrap">
                      {entree.reference === null ? '—' : formaterFcfa(Number(entree.reference.montant))}
                    </td>
                    <td className="px-3 py-2 text-right">
                      <Bouton
                        variante="discret"
                        taille="mini"
                        icone={<IconeVoir />}
                        onClick={() => setLigneOuverte(ligneOuverte === entree.id ? null : entree.id)}
                      >
                        {ligneOuverte === entree.id ? 'Masquer' : 'Détail'}
                      </Bouton>
                    </td>
                  </tr>
                  {ligneOuverte === entree.id && (
                    <tr className="border-b border-border bg-nested">
                      <td colSpan={6} className="px-3 py-3">
                        <Diff avant={entree.avant} apres={entree.apres} action={entree.action} />
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data && data.pagination.pages > 1 && (
        <div className="mt-3 flex items-center justify-between text-sm text-muted">
          <span className="chiffres">
            Page {data.pagination.page} sur {data.pagination.pages} — {data.pagination.total} écriture(s)
          </span>
          <div className="flex gap-2">
            <Bouton variante="secondaire" taille="compacte" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Précédente
            </Bouton>
            <Bouton
              variante="secondaire"
              taille="compacte"
              disabled={page >= data.pagination.pages}
              onClick={() => setPage(page + 1)}
            >
              Suivante
            </Bouton>
          </div>
        </div>
      )}
    </section>
  )
}

function Reference({ entree }: { entree: EntreeJournalBudget }) {
  const type = LIBELLES_TYPE[entree.entiteType] ?? entree.entiteType

  if (entree.reference === null) {
    return (
      <span className="text-muted">
        {type} <span className="chiffres">#{entree.entiteId}</span> — ligne supprimée depuis
      </span>
    )
  }

  return (
    <span>
      <span className="chiffres mr-1 text-muted">{entree.reference.activite.code}</span>
      {type}
      {entree.reference.libelle !== null && <span className="text-muted"> — {entree.reference.libelle}</span>}
      <span className="chiffres ml-1 text-muted">({entree.reference.exercice})</span>
    </span>
  )
}

/**
 * Plomberie : identifiants techniques, horodatages d'ORM et clés étrangères
 * rendues en uuid. Le journal les enregistre — c'est bien qu'il le fasse,
 * une entrée doit pouvoir être reliée à sa ligne — mais un auditeur qui
 * ouvre une écriture veut savoir ce qui a changé dans l'argent et dans le
 * statut, pas relire un uuid. L'onglet d'administration, lui, montre tout.
 */
const CHAMPS_TECHNIQUES = new Set([
  'uuid',
  'creeLe',
  'modifieLe',
  'supprimeLe',
  'creePar',
  'activite',
  'sousActivite',
  'exercice',
  'engagement',
])

/** Champs dont la valeur est un montant FCFA entier, à présenter comme tel. */
const CHAMPS_MONTANT = new Set(['montant', 'q1', 'q2', 'q3', 'q4', 'montantValideCopil'])

/**
 * État avant et après, champ par champ. Le journal ne stocke que les champs
 * réellement modifiés, donc la liste dit exactement ce qui a bougé — y
 * compris un passage de `brouillon` à `valide`, qui est la trace d'une
 * validation.
 */
function Diff({
  avant,
  apres,
  action,
}: {
  avant: Record<string, unknown> | null
  apres: Record<string, unknown> | null
  action: string
}) {
  const champs = [...new Set([...Object.keys(avant ?? {}), ...Object.keys(apres ?? {})])]
    .filter((champ) => !CHAMPS_TECHNIQUES.has(champ))
    .filter((champ) => avant?.[champ] !== undefined || apres?.[champ] !== null)

  // À la création, « avant » est vide par construction : une colonne de
  // tirets n'apprend rien.
  const creation = action === 'creation'

  if (champs.length === 0) {
    return <p className="text-xs text-muted">Aucun détail métier enregistré pour cette écriture.</p>
  }

  return (
    <table className="w-full max-w-2xl text-xs">
      <thead>
        <tr className="text-left text-muted">
          <th className="py-1 pr-4 font-semibold">Champ</th>
          {!creation && <th className="py-1 pr-4 font-semibold">Avant</th>}
          <th className="py-1 font-semibold">{creation ? 'Valeur' : 'Après'}</th>
        </tr>
      </thead>
      <tbody>
        {champs.map((champ) => (
          <tr key={champ}>
            <td className="py-1 pr-4">{champ}</td>
            {!creation && <td className="chiffres py-1 pr-4 text-muted">{formaterValeur(champ, avant?.[champ])}</td>}
            <td className="chiffres py-1">{formaterValeur(champ, apres?.[champ])}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function formaterValeur(champ: string, valeur: unknown): string {
  if (valeur === null || valeur === undefined) return '—'
  if (CHAMPS_MONTANT.has(champ)) return formaterFcfa(Number(valeur))
  if (typeof valeur === 'object') return JSON.stringify(valeur)

  return String(valeur)
}
