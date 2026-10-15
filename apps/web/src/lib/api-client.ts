const BASE_URL = import.meta.env.VITE_API_URL ?? '/api/v1'

export class ApiError extends Error {
  readonly status: number
  readonly probleme?: unknown

  constructor(message: string, status: number, probleme?: unknown) {
    super(message)
    this.status = status
    this.probleme = probleme
  }
}

async function requete<T>(chemin: string, options: RequestInit = {}): Promise<T> {
  const reponse = await fetch(`${BASE_URL}${chemin}`, {
    ...options,
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...options.headers,
    },
  })

  if (!reponse.ok) {
    const probleme = await reponse.json().catch(() => undefined)
    const estProbleme = typeof probleme === 'object' && probleme !== null
    const probleme2 = probleme as { title?: unknown; detail?: unknown } | undefined
    // RFC 7807 : "detail" porte le message spécifique à l'instance (ex. le
    // montant précis qui dépasse la dotation), "title" n'est que la
    // catégorie générique — préférer detail quand il est présent.
    const message = estProbleme && typeof probleme2?.detail === 'string'
      ? probleme2.detail
      : estProbleme && typeof probleme2?.title === 'string'
        ? probleme2.title
        : `Erreur ${reponse.status}`

    // Filet de sécurité : un mot de passe temporaire ferme toute l'API
    // (ChangementMotDePasseRequisListener). Le routeur y conduit déjà à la
    // connexion ; ceci couvre un compte réinitialisé en cours de session.
    if (
      reponse.status === 403 &&
      (probleme as { type?: unknown } | undefined)?.type === 'changement-mot-de-passe-requis' &&
      window.location.pathname !== '/changer-mot-de-passe'
    ) {
      window.location.assign('/changer-mot-de-passe')
    }

    throw new ApiError(message, reponse.status, probleme)
  }

  if (reponse.status === 204) {
    return undefined as T
  }

  return reponse.json() as Promise<T>
}

export const apiClient = {
  get: <T>(chemin: string) => requete<T>(chemin),
  post: <T>(chemin: string, corps?: unknown) =>
    requete<T>(chemin, { method: 'POST', body: corps ? JSON.stringify(corps) : undefined }),
  patch: <T>(chemin: string, corps?: unknown) =>
    requete<T>(chemin, { method: 'PATCH', body: corps ? JSON.stringify(corps) : undefined }),
  put: <T>(chemin: string, corps?: unknown) =>
    requete<T>(chemin, { method: 'PUT', body: corps ? JSON.stringify(corps) : undefined }),
  delete: <T>(chemin: string) => requete<T>(chemin, { method: 'DELETE' }),
}
