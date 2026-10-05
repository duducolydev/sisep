const BASE_URL = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'

/** § 6.2 du brief — téléchargement direct (cookie de session envoyé sur la navigation GET, SameSite=Lax). */
export function urlExportBudget(exercice?: number, composanteUuid?: string): string {
  const params = new URLSearchParams()
  if (exercice) params.set('exercice', String(exercice))
  if (composanteUuid) params.set('composante', composanteUuid)
  const suffixe = params.toString()
  return `${BASE_URL}/exports/budget.xlsx${suffixe ? `?${suffixe}` : ''}`
}

export function urlExportCadreResultats(exercice?: number): string {
  const params = new URLSearchParams()
  if (exercice) params.set('exercice', String(exercice))
  const suffixe = params.toString()
  return `${BASE_URL}/exports/cadre-resultats.xlsx${suffixe ? `?${suffixe}` : ''}`
}

export function urlExportRapportTrimestriel(periode: string, exercice?: number): string {
  const params = new URLSearchParams({ periode })
  if (exercice) params.set('exercice', String(exercice))
  return `${BASE_URL}/exports/rapport-trimestriel.pdf?${params.toString()}`
}

export function urlExportInitiativesXlsx(): string {
  return `${BASE_URL}/exports/initiatives.xlsx`
}

export function urlExportInitiativesPdf(): string {
  return `${BASE_URL}/exports/initiatives.pdf`
}

export function urlTelechargerPieceJointe(uuid: string): string {
  return `${BASE_URL}/pieces-jointes/${uuid}/telecharger`
}
