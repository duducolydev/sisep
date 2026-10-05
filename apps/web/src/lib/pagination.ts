/** Découpe côté client une collection déjà filtrée — évite de dupliquer la pagination serveur sur des listes de taille modeste. */
export function trancherPage<T>(elements: T[], page: number, taille: number): T[] {
  const debut = (page - 1) * taille

  return elements.slice(debut, debut + taille)
}
