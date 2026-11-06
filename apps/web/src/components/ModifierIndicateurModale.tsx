import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { modifierIndicateur, type Indicateur } from '@/lib/indicateurs'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import type { LigneExecution } from '@/lib/budget'
import { champNombre, champNombreFacultatif } from '@/lib/nombre-decimal'

// Mêmes règles qu'à la définition (colonnes de la table `indicateurs`).
// Redéfinition complète : code, type, sens, référence et fréquence se
// corrigent aussi — le taux d'atteinte, jamais stocké, suit aussitôt.
const schema = z.object({
  code: z.string().trim().min(1, 'Le code est requis.').max(20, '20 caractères au plus.'),
  type: z.enum(['IOD', 'IRI']),
  libelle: z.string().min(1, 'Le libellé est requis.').max(255, '255 caractères au plus.'),
  precisionLibelle: z.string().max(255, '255 caractères au plus.').optional(),
  unite: z.string().min(1, "L'unité est requise.").max(40, '40 caractères au plus.'),
  sens: z.enum(['croissant', 'decroissant']),
  valeurReference: champNombre('La valeur de référence est requise.'),
  dateReference: z.string().min(1, 'La date de référence est requise.'),
  valeurIntermediaire: champNombreFacultatif(),
  dateIntermediaire: z.string().optional(),
  valeurCible: champNombre('La valeur cible est requise.'),
  dateCible: z.string().min(1, 'La date cible est requise.'),
  frequence: z.enum(['mensuelle', 'trimestrielle', 'semestrielle', 'annuelle']),
  source: z.string().min(1, 'La source est requise.').max(160, '160 caractères au plus.'),
  actif: z.boolean(),
  composante: z.string(),
})

type FormValues = z.infer<typeof schema>

interface ModifierIndicateurModaleProps {
  indicateur: Indicateur
  composantes: LigneExecution[]
  onFermer: () => void
}

const CHAMP = 'rounded-[var(--radius-controle)] border border-border px-3 py-2'

export function ModifierIndicateurModale({ indicateur, composantes, onFermer }: ModifierIndicateurModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: indicateur.code,
      type: indicateur.type,
      libelle: indicateur.libelle,
      precisionLibelle: indicateur.precisionLibelle ?? '',
      unite: indicateur.unite,
      sens: indicateur.sens,
      valeurReference: indicateur.valeurReference,
      dateReference: indicateur.dateReference.slice(0, 10),
      valeurIntermediaire: indicateur.valeurIntermediaire ?? '',
      dateIntermediaire: indicateur.dateIntermediaire?.slice(0, 10) ?? '',
      valeurCible: indicateur.valeurCible,
      dateCible: indicateur.dateCible.slice(0, 10),
      frequence: indicateur.frequence as FormValues['frequence'],
      source: indicateur.source,
      actif: indicateur.actif,
      composante: indicateur.composante?.uuid ?? '',
    },
  })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await modifierIndicateur(indicateur.uuid, {
        ...valeurs,
        valeurIntermediaire: valeurs.valeurIntermediaire?.trim() === '' ? null : valeurs.valeurIntermediaire,
        dateIntermediaire: valeurs.dateIntermediaire?.trim() === '' ? null : valeurs.dateIntermediaire,
        composante: valeurs.composante === '' ? null : valeurs.composante,
      })
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['indicateurs'] }),
        queryClient.invalidateQueries({ queryKey: ['indicateur', indicateur.uuid] }),
        queryClient.invalidateQueries({ queryKey: ['synthese'] }),
      ])
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre={`Modifier ${indicateur.code}`} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto">
        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="code">Code</label>
            <input id="code" className={`chiffres ${CHAMP}`} {...register('code')} />
            {errors.code && <p className="text-bad text-sm">{errors.code.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="type">Type</label>
            <select id="type" className={CHAMP} {...register('type')}>
              <option value="IOD">IOD — objectif de développement</option>
              <option value="IRI">IRI — résultat intermédiaire</option>
            </select>
          </div>
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="libelle">Libellé</label>
          <input id="libelle" className={CHAMP} {...register('libelle')} />
          {errors.libelle && <p className="text-bad text-sm">{errors.libelle.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="precisionLibelle">Précision (optionnel)</label>
          <input id="precisionLibelle" className={CHAMP} {...register('precisionLibelle')} />
          {errors.precisionLibelle && <p className="text-bad text-sm">{errors.precisionLibelle.message}</p>}
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="composante">Rattachement</label>
          <select id="composante" className={CHAMP} {...register('composante')}>
            <option value="">Objectif général (indicateur transversal)</option>
            {composantes.map((c) => (
              <option key={c.uuid} value={c.uuid}>
                {c.code} — {c.libelle}
              </option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="unite">Unité</label>
            <input id="unite" className={CHAMP} {...register('unite')} />
            {errors.unite && <p className="text-bad text-sm">{errors.unite.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="sens">Sens</label>
            <select id="sens" className={CHAMP} {...register('sens')}>
              <option value="croissant">Croissant</option>
              <option value="decroissant">Décroissant</option>
            </select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="valeurReference">Valeur de référence</label>
            <input id="valeurReference" className={`chiffres ${CHAMP}`} {...register('valeurReference')} />
            {errors.valeurReference && <p className="text-bad text-sm">{errors.valeurReference.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="dateReference">Date de référence</label>
            <input id="dateReference" type="date" className={`chiffres ${CHAMP}`} {...register('dateReference')} />
            {errors.dateReference && <p className="text-bad text-sm">{errors.dateReference.message}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="valeurIntermediaire">Objectif intermédiaire (optionnel)</label>
            <input id="valeurIntermediaire" className={`chiffres ${CHAMP}`} {...register('valeurIntermediaire')} />
            {errors.valeurIntermediaire && <p className="text-bad text-sm">{errors.valeurIntermediaire.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="dateIntermediaire">Date intermédiaire (optionnel)</label>
            <input id="dateIntermediaire" type="date" className={`chiffres ${CHAMP}`} {...register('dateIntermediaire')} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="valeurCible">Valeur cible</label>
            <input id="valeurCible" className={`chiffres ${CHAMP}`} {...register('valeurCible')} />
            {errors.valeurCible && <p className="text-bad text-sm">{errors.valeurCible.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="dateCible">Date cible</label>
            <input id="dateCible" type="date" className={`chiffres ${CHAMP}`} {...register('dateCible')} />
            {errors.dateCible && <p className="text-bad text-sm">{errors.dateCible.message}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="frequence">Fréquence</label>
            <select id="frequence" className={CHAMP} {...register('frequence')}>
              <option value="mensuelle">Mensuelle</option>
              <option value="trimestrielle">Trimestrielle</option>
              <option value="semestrielle">Semestrielle</option>
              <option value="annuelle">Annuelle</option>
            </select>
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="source">Source</label>
            <input id="source" className={CHAMP} {...register('source')} />
            {errors.source && <p className="text-bad text-sm">{errors.source.message}</p>}
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" {...register('actif')} />
          Indicateur actif
        </label>

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
            {isSubmitting ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
