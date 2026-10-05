import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { creerStructure, modifierStructure, type StructureDetail } from '@/lib/parties-prenantes'
import { LIBELLES_TYPE_STRUCTURE } from '@/lib/types-structure'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  sigle: z.string().min(1, 'Le sigle est requis.').max(20),
  denomination: z.string().min(1, 'La dénomination est requise.'),
  type: z.enum(['chef_de_file', 'direction_ansd', 'cellule_ansd', 'partenaire_national', 'partenaire_technique', 'partenaire_formation', 'bailleur']),
  telephone: z.string().optional(),
  // Obligatoire : destinataire du système d'alertes par courriel (rapports en retard, vigilance).
  email: z.string().min(1, "L'email est requis pour recevoir les alertes.").email('Email invalide.'),
  adresse: z.string().optional(),
})

type FormValues = z.infer<typeof schema>

interface StructureModaleProps {
  structure?: StructureDetail
  onFermer: () => void
}

/** Créer / modifier une structure (§ 2.5, § 2.6 — CRUD des parties prenantes). */
export function StructureModale({ structure, onFermer }: StructureModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: structure
      ? {
          sigle: structure.sigle,
          denomination: structure.denomination,
          type: structure.type as FormValues['type'],
          telephone: structure.telephone ?? '',
          email: structure.email ?? '',
          adresse: structure.adresse ?? '',
        }
      : { type: 'direction_ansd' },
  })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      if (structure) {
        await modifierStructure(structure.uuid, valeurs)
      } else {
        await creerStructure(valeurs)
      }
      await queryClient.invalidateQueries({ queryKey: ['structures'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre={structure ? `Modifier ${structure.sigle}` : 'Ajouter une structure'} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="sigle">Sigle</label>
            <input id="sigle" className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('sigle')} />
            {errors.sigle && <p className="text-bad text-sm">{errors.sigle.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="type">Type</label>
            <select id="type" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('type')}>
              {Object.entries(LIBELLES_TYPE_STRUCTURE).map(([valeur, libelle]) => (
                <option key={valeur} value={valeur}>
                  {libelle}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="denomination">Dénomination</label>
          <input
            id="denomination"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('denomination')}
          />
          {errors.denomination && <p className="text-bad text-sm">{errors.denomination.message}</p>}
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="telephone">Téléphone (optionnel)</label>
            <input
              id="telephone"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('telephone')}
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="email">Email</label>
            <input
              id="email"
              type="email"
              required
              aria-required="true"
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('email')}
            />
            {errors.email && <p className="text-bad text-sm">{errors.email.message}</p>}
            {!errors.email && <p className="text-xs text-muted">Utilisé pour les alertes par courriel.</p>}
          </div>
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="adresse">Adresse (optionnel)</label>
          <input id="adresse" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('adresse')} />
        </div>

        <div className="mt-4 flex justify-end gap-3 border-t border-border pt-4">
          <button
            type="button"
            onClick={onFermer}
            className="rounded-[var(--radius-controle)] border border-border bg-surface px-4 py-2 font-semibold text-text transition-colors hover:border-accent hover:text-accent"
          >
            Annuler
          </button>
          <button
            type="submit"
            disabled={isSubmitting}
            className="rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d disabled:opacity-60"
          >
            {isSubmitting ? 'Enregistrement…' : structure ? 'Enregistrer' : 'Ajouter'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
