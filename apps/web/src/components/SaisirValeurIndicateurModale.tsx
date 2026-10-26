import { useState } from 'react'
import { useFieldArray, useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { DropzoneFichier } from './DropzoneFichier'
import { IconeBouton, IconePlus, IconeAnnuler } from './Icones'
import { saisirValeur, televerserPieceJointeValeur } from '@/lib/indicateurs'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { ChoixTypeDocument } from './ChoixTypeDocument'
import { erreurNature, NATURE_VIDE, type NatureDocument } from '@/lib/types-document'

const schema = z
  .object({
    periode: z.string().min(1, 'La période est requise.'),
    mode: z.enum(['ajustement', 'absolu']),
    valeur: z.string(),
    commentaire: z.string().optional(),
    desagregations: z.array(
      z.object({
        dimension: z.string().min(1, 'Requis'),
        modalite: z.string().min(1, 'Requis'),
        valeur: z.string().min(1, 'Requis'),
      }),
    ),
  })
  .superRefine((donnees, ctx) => {
    if (donnees.valeur.trim() === '') {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['valeur'],
        message: donnees.mode === 'ajustement' ? "L'ajustement est requis." : 'La valeur est requise.',
      })
    }
  })

type FormValues = z.infer<typeof schema>

interface SaisirValeurIndicateurModaleProps {
  indicateurUuid: string
  onFermer: () => void
}

/** Mode « Ajustement » par défaut (§ décision) : la quantité saisie est un delta +/- depuis la dernière saisie, pas la valeur absolue. */
export function SaisirValeurIndicateurModale({ indicateurUuid, onFermer }: SaisirValeurIndicateurModaleProps) {
  const [erreur, setErreur] = useState<string | null>(null)
  const [fichier, setFichier] = useState<File | null>(null)
  const [nature, setNature] = useState<NatureDocument>(NATURE_VIDE)

  const {
    register,
    control,
    watch,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { mode: 'ajustement', valeur: '', desagregations: [] } })

  const { fields, append, remove } = useFieldArray({ control, name: 'desagregations' })
  const mode = watch('mode')

  async function onSubmit(donnees: FormValues) {
    setErreur(null)
    // Vérifié avant la saisie : une valeur enregistrée dont la pièce serait
    // ensuite refusée laisserait l'utilisateur à mi-chemin.
    const erreurType = fichier ? erreurNature(nature) : null
    if (erreurType !== null) {
      setErreur(erreurType)

      return
    }
    try {
      const reponse = await saisirValeur(indicateurUuid, {
        periode: donnees.periode,
        ...(donnees.mode === 'ajustement' ? { delta: donnees.valeur } : { valeur: donnees.valeur }),
        commentaire: donnees.commentaire,
        desagregations: donnees.desagregations,
      })
      if (fichier) {
        await televerserPieceJointeValeur(reponse.uuid, fichier, nature)
      }
      // La liste des indicateurs et le cadre de résultats du tableau de bord
      // affichent aussi la valeur en attente : sans eux, jusqu'à 30 s
      // d'ancien affichage (staleTime).
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['indicateur', indicateurUuid] }),
        queryClient.invalidateQueries({ queryKey: ['indicateurs'] }),
        queryClient.invalidateQueries({ queryKey: ['synthese'] }),
      ])
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError || e instanceof Error ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre="Saisir une valeur périodique" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="flex gap-0.5 rounded-[var(--radius-controle)] bg-nested p-0.5 text-sm">
          <label
            className={`flex-1 cursor-pointer rounded-[calc(var(--radius-controle)-2px)] py-1.5 text-center font-semibold transition-colors ${
              mode === 'ajustement' ? 'bg-accent text-white' : 'text-muted hover:text-accent'
            }`}
          >
            <input type="radio" value="ajustement" className="sr-only" {...register('mode')} />
            Ajustement
          </label>
          <label
            className={`flex-1 cursor-pointer rounded-[calc(var(--radius-controle)-2px)] py-1.5 text-center font-semibold transition-colors ${
              mode === 'absolu' ? 'bg-accent text-white' : 'text-muted hover:text-accent'
            }`}
          >
            <input type="radio" value="absolu" className="sr-only" {...register('mode')} />
            Valeur absolue
          </label>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="periode">Période</label>
            <input
              id="periode"
              type="date"
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('periode')}
            />
            {errors.periode && <p className="text-bad text-sm">{errors.periode.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="valeur">{mode === 'ajustement' ? 'Ajustement (+/-)' : 'Valeur'}</label>
            <input
              id="valeur"
              placeholder={mode === 'ajustement' ? 'ex. +5 ou -2.5' : undefined}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('valeur')}
            />
            {errors.valeur && <p className="text-bad text-sm">{errors.valeur.message}</p>}
          </div>
        </div>
        {mode === 'ajustement' && (
          <p className="-mt-3 text-xs text-muted">Quantité en plus (+) ou en moins (-) depuis la dernière saisie (validée ou non).</p>
        )}

        <div className="flex flex-col gap-1">
          <label htmlFor="commentaire">Commentaire (optionnel)</label>
          <textarea
            id="commentaire"
            rows={2}
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('commentaire')}
          />
        </div>

        <div className="flex flex-col gap-1">
          <span className="text-sm">Pièce justificative (optionnel)</span>
          <DropzoneFichier fichier={fichier} onChange={setFichier} />
          {fichier && <ChoixTypeDocument id="valeur-type-document" nature={nature} onChange={setNature} />}
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-sm font-semibold">Désagrégations (optionnel)</span>
            <Bouton
              variante="discret"
              taille="mini"
              icone={<IconePlus />}
              onClick={() => append({ dimension: '', modalite: '', valeur: '' })}
            >
              Ajouter
            </Bouton>
          </div>
          {fields.map((champ, index) => (
            <div key={champ.id} className="mb-2 grid grid-cols-[1fr_1fr_1fr_auto] items-start gap-2">
              <input
                placeholder="Dimension (ex. sexe)"
                className="rounded-[var(--radius-controle)] border border-border px-2 py-1.5 text-sm"
                {...register(`desagregations.${index}.dimension`)}
              />
              <input
                placeholder="Modalité (ex. femmes)"
                className="rounded-[var(--radius-controle)] border border-border px-2 py-1.5 text-sm"
                {...register(`desagregations.${index}.modalite`)}
              />
              <input
                placeholder="Valeur"
                className="chiffres rounded-[var(--radius-controle)] border border-border px-2 py-1.5 text-sm"
                {...register(`desagregations.${index}.valeur`)}
              />
              <IconeBouton
                libelle="Retirer la désagrégation"
                onClick={() => remove(index)}
                classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
              >
                <IconeAnnuler />
              </IconeBouton>
            </div>
          ))}
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
            {isSubmitting ? 'Enregistrement…' : 'Saisir'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
