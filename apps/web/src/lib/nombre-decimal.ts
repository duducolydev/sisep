import { z } from 'zod'

/**
 * Même règle que App\Indicateur\NombreDecimal côté API : valeurs
 * d'indicateurs en DECIMAL(18,4), saisies à la française (« 0,5 »,
 * « 1 000 »). Virgule et séparateurs de milliers sont acceptés ; une unité
 * collée (« 100 % ») ou du texte est refusé avec un message sous le champ
 * plutôt que de remonter « Erreur interne ».
 */
const MOTIF = /^-?\d{1,14}(\.\d{1,4})?$/

export function normaliserNombreDecimal(saisie: string): string | null {
  const nombre = saisie.trim().replace(/[\s  ]/g, '').replace(',', '.')

  return MOTIF.test(nombre) ? nombre : null
}

export const MESSAGE_NOMBRE = 'Saisissez un nombre (ex. 12, 0,5 ou 1 000), sans unité, avec au plus 4 décimales.'

/** Champ numérique requis. */
export const champNombre = (messageRequis: string) =>
  z
    .string()
    .min(1, messageRequis)
    .refine((v) => normaliserNombreDecimal(v) !== null, MESSAGE_NOMBRE)

/** Champ numérique facultatif : vide accepté. */
export const champNombreFacultatif = () =>
  z
    .string()
    .optional()
    .refine((v) => v === undefined || v.trim() === '' || normaliserNombreDecimal(v) !== null, MESSAGE_NOMBRE)
