import { useSearchParams } from 'react-router'
import { valeurParDefaut, type GranularitePeriode } from '@/lib/periode'
import { IconeCalendrier } from './Icones'

const OPTIONS: { valeur: GranularitePeriode; libelle: string }[] = [
  { valeur: 'aucun', libelle: 'Toutes périodes' },
  { valeur: 'annee', libelle: 'Année' },
  { valeur: 'trimestre', libelle: 'Trimestre' },
  { valeur: 'mois', libelle: 'Mois' },
  { valeur: 'jour', libelle: 'Jour' },
]

/**
 * Filtre année/trimestre/mois/jour sur une liste datée (points d'attention,
 * activité récente, série historique d'un indicateur) — § décision : ne
 * s'applique jamais aux agrégats budgétaires (année seule, via
 * SelecteurExercice), seulement à ces listes déjà renvoyées telles
 * quelles. État piloté par l'URL (`granularite`, `periode`), lu ailleurs
 * via usePeriodeFiltre().
 */
export function SelecteurPeriode() {
  const [searchParams, setSearchParams] = useSearchParams()
  const granularite = (searchParams.get('granularite') as GranularitePeriode | null) ?? 'aucun'
  const valeur = searchParams.get('periode') ?? ''

  function changerGranularite(suivante: GranularitePeriode) {
    const suivant = new URLSearchParams(searchParams)
    if (suivante === 'aucun') {
      suivant.delete('granularite')
      suivant.delete('periode')
    } else {
      suivant.set('granularite', suivante)
      suivant.set('periode', valeurParDefaut(suivante))
    }
    setSearchParams(suivant, { replace: true })
  }

  function changerValeur(suivante: string) {
    const suivant = new URLSearchParams(searchParams)
    suivant.set('periode', suivante)
    setSearchParams(suivant, { replace: true })
  }

  const [anneeTrimestre, trimestre] = granularite === 'trimestre' && valeur.includes('-T') ? valeur.split('-T') : ['', '']

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="flex gap-0.5 rounded-[var(--radius-controle)] bg-nested p-0.5 text-xs">
        {OPTIONS.map((o) => (
          <button
            key={o.valeur}
            type="button"
            onClick={() => changerGranularite(o.valeur)}
            className={`rounded-[calc(var(--radius-controle)-2px)] px-2.5 py-1.5 font-semibold transition-colors ${
              granularite === o.valeur ? 'bg-accent text-white' : 'text-muted hover:text-accent'
            }`}
          >
            {o.libelle}
          </button>
        ))}
      </div>

      {granularite === 'annee' && (
        <div className="flex items-center gap-1">
          <IconeCalendrier />
          <input
            type="number"
            aria-label="Année"
            value={valeur}
            min={2000}
            max={2100}
            onChange={(e) => changerValeur(e.target.value)}
            className="chiffres w-20 rounded-[var(--radius-controle)] border border-border bg-surface px-2 py-1.5 text-xs"
          />
        </div>
      )}

      {granularite === 'mois' && (
        <input
          type="month"
          value={valeur}
          onChange={(e) => changerValeur(e.target.value)}
          className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-2 py-1.5 text-xs"
        />
      )}

      {granularite === 'jour' && (
        <input
          type="date"
          value={valeur}
          onChange={(e) => changerValeur(e.target.value)}
          className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-2 py-1.5 text-xs"
        />
      )}

      {granularite === 'trimestre' && (
        <div className="flex items-center gap-1">
          <IconeCalendrier />
          <input
            type="number"
            value={anneeTrimestre}
            onChange={(e) => changerValeur(`${e.target.value}-T${trimestre}`)}
            className="chiffres w-20 rounded-[var(--radius-controle)] border border-border bg-surface px-2 py-1.5 text-xs"
          />
          <select
            value={trimestre}
            onChange={(e) => changerValeur(`${anneeTrimestre}-T${e.target.value}`)}
            className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-2 py-1.5 text-xs"
          >
            {['1', '2', '3', '4'].map((t) => (
              <option key={t} value={t}>
                T{t}
              </option>
            ))}
          </select>
        </div>
      )}
    </div>
  )
}
