import { z } from 'zod'

/**
 * Le backend est en PHP (Symfony), le front en TypeScript : contrairement au
 * scénario NestJS envisagé au § 0 du brief, les types ne peuvent plus être
 * partagés au niveau du langage. Ces schémas sont donc maintenus à la main et
 * doivent rester synchronisés avec les DTO Symfony (apps/api/src/Dto) — un
 * générateur à partir de l'OpenAPI exposé par l'API serait la suite logique
 * si la dérive devient un problème en pratique.
 */

export const uuidSchema = z.string().uuid()

export const paginationSchema = z.object({
  page: z.number().int().positive(),
  taille: z.number().int().positive(),
  total: z.number().int().nonnegative(),
  pages: z.number().int().nonnegative(),
})

export function enveloppePaginee<T extends z.ZodTypeAny>(schemaDonnee: T) {
  return z.object({
    donnees: z.array(schemaDonnee),
    pagination: paginationSchema,
  })
}

/** RFC 7807 (application/problem+json) — § 6 du brief. */
export const problemeSchema = z.object({
  type: z.string().optional(),
  title: z.string(),
  status: z.number().int(),
  detail: z.string().optional(),
  instance: z.string().optional(),
})

export type Probleme = z.infer<typeof problemeSchema>
export type Pagination = z.infer<typeof paginationSchema>
