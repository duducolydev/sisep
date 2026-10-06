import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { exercicesQueryOptions } from '@/lib/tableau-de-bord'

/** Année budgétaire piloté par l'URL (`exercice`) — § décision : câblée de bout en bout sur les agrégats budgétaires, contrairement au trimestre/mois/jour. Sans sélection, le serveur retient l'année en cours, et le plus récent exercice seulement si elle n'existe pas. */
export function SelecteurExercice({ projetUuid }: { projetUuid: string | undefined }) {
  const { data: exercices } = useQuery(exercicesQueryOptions(projetUuid))
  const [searchParams, setSearchParams] = useSearchParams()
  const exerciceParam = searchParams.get('exercice')

  if (!exercices || exercices.donnees.length <= 1) {
    return null
  }

  function changerExercice(annee: string) {
    const suivant = new URLSearchParams(searchParams)
    if (annee === '') {
      suivant.delete('exercice')
    } else {
      suivant.set('exercice', annee)
    }
    setSearchParams(suivant, { replace: true })
  }

  return (
    <select
      value={exerciceParam ?? ''}
      onChange={(e) => changerExercice(e.target.value)}
      className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-2.5 py-1.5 text-sm"
      aria-label="Exercice"
    >
      <option value="">Exercice en cours</option>
      {exercices.donnees.map((e) => (
        <option key={e.annee} value={e.annee}>
          Exercice {e.annee}
        </option>
      ))}
    </select>
  )
}
