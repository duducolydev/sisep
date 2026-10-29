import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { IconeCopier, IconeValider } from './Icones'
import { creerUtilisateur } from '@/lib/utilisateurs'
import { structuresDetailQueryOptions } from '@/lib/parties-prenantes'
import { rolesQueryOptions } from '@/lib/admin'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  email: z.string().min(1, "L'email est requis.").email('Email invalide.'),
  nom: z.string().min(1, 'Le nom est requis.'),
  prenom: z.string().min(1, 'Le prénom est requis.'),
  fonction: z.string().optional(),
  structure: z.string().optional(),
  role: z.string().optional(),
  roleStructure: z.string().optional(),
})

type FormValues = z.infer<typeof schema>

interface CreerUtilisateurModaleProps {
  onFermer: () => void
}

/** Création d'un compte de connexion (§ 4.5, § 7) : le mot de passe temporaire n'est visible qu'une seule fois, ici. */
export function CreerUtilisateurModale({ onFermer }: CreerUtilisateurModaleProps) {
  const { data: structures } = useQuery(structuresDetailQueryOptions())
  const { data: roles } = useQuery(rolesQueryOptions())

  const [erreur, setErreur] = useState<string | null>(null)
  const [creation, setCreation] = useState<{ email: string; motDePasseTemporaire: string; courrielEnvoye: boolean } | null>(null)
  const [copie, setCopie] = useState(false)

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) })

  const roleChoisi = watch('role')
  const exigeGlobale = (code: string | undefined) => roles?.donnees.find((r) => r.code === code)?.porteeGlobaleExigee ?? false

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      const reponse = await creerUtilisateur({
        email: valeurs.email,
        nom: valeurs.nom,
        prenom: valeurs.prenom,
        fonction: valeurs.fonction || undefined,
        structure: valeurs.structure || undefined,
        roles: valeurs.role
          ? [{ role: valeurs.role, structure: exigeGlobale(valeurs.role) ? undefined : valeurs.roleStructure || undefined }]
          : [],
      })
      await queryClient.invalidateQueries({ queryKey: ['admin', 'utilisateurs'] })
      setCreation({
        email: reponse.email,
        motDePasseTemporaire: reponse.motDePasseTemporaire,
        courrielEnvoye: reponse.courrielEnvoye,
      })
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  async function copierMotDePasse() {
    if (!creation) return
    await navigator.clipboard.writeText(creation.motDePasseTemporaire)
    setCopie(true)
  }

  if (creation) {
    return (
      <Modale titre="Compte créé" onFermer={onFermer}>
        <p className="mb-3 text-sm">
          Le compte <span className="font-semibold">{creation.email}</span> a été créé.{' '}
          {creation.courrielEnvoye
            ? "Le mot de passe temporaire vient de lui être envoyé par courriel. Il ne sera plus jamais affiché ici, et devra être changé à la première connexion."
            : "Le courriel n'a pas pu partir : communiquez ce mot de passe temporaire hors-bande (téléphone, en main propre…). Il ne sera plus jamais affiché, et devra être changé à la première connexion."}
        </p>
        {!creation.courrielEnvoye && (
          <p className="mb-3 rounded-[var(--radius-controle)] bg-warn-bg px-3 py-2 text-sm text-warn">
            Envoi du courriel impossible — vérifiez la configuration SMTP (MAILER_DSN).
          </p>
        )}
        <div className="mb-4 flex items-center justify-between gap-3 rounded-[var(--radius-controle)] border border-border bg-nested px-3 py-2">
          <span className="chiffres select-all break-all">{creation.motDePasseTemporaire}</span>
          <Bouton
            variante="discret"
            taille="mini"
            icone={copie ? <IconeValider /> : <IconeCopier />}
            onClick={() => void copierMotDePasse()}
          >
            {copie ? 'Copié' : 'Copier'}
          </Bouton>
        </div>
        <div className="flex justify-end">
          <button
            type="button"
            onClick={onFermer}
            className="rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d"
          >
            Terminé
          </button>
        </div>
      </Modale>
    )
  }

  return (
    <Modale titre="Ajouter un compte" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="prenom">Prénom</label>
            <input id="prenom" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('prenom')} />
            {errors.prenom && <p className="text-bad text-sm">{errors.prenom.message}</p>}
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="nom">Nom</label>
            <input id="nom" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('nom')} />
            {errors.nom && <p className="text-bad text-sm">{errors.nom.message}</p>}
          </div>
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
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="fonction">Fonction (optionnel)</label>
          <input id="fonction" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('fonction')} />
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="structure">Structure (optionnel)</label>
          <select id="structure" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('structure')}>
            <option value="">Aucune</option>
            {structures?.donnees.map((s) => (
              <option key={s.uuid} value={s.uuid}>
                {s.sigle}
              </option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="role">Rôle (optionnel)</label>
            <select
              id="role"
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
              {...register('role', {
                onChange: (e: { target: { value: string } }) => {
                  if (exigeGlobale(e.target.value)) setValue('roleStructure', '')
                },
              })}
            >
              <option value="">Aucun rôle</option>
              {roles?.donnees.map((r) => (
                <option key={r.uuid} value={r.code}>
                  {r.libelle}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="roleStructure">Portée du rôle</label>
            <select
              id="roleStructure"
              disabled={!roleChoisi || exigeGlobale(roleChoisi)}
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2 disabled:opacity-50"
              {...register('roleStructure')}
            >
              <option value="">Portée globale</option>
              {structures?.donnees.map((s) => (
                <option key={s.uuid} value={s.uuid}>
                  {s.sigle}
                </option>
              ))}
            </select>
            {exigeGlobale(roleChoisi) && <p className="text-xs text-muted">Ce rôle s'attribue sur tout le projet.</p>}
          </div>
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
            {isSubmitting ? 'Création…' : 'Créer le compte'}
          </button>
        </div>
      </form>
    </Modale>
  )
}
