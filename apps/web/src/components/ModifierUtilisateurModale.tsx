import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { attribuerMotDePasseTemporaire, definirRolesUtilisateur, modifierUtilisateur, type Utilisateur } from '@/lib/utilisateurs'
import { rolesQueryOptions } from '@/lib/admin'
import { IconeAnnuler, IconeBouton, IconeCopier, IconePlus, IconeValider } from './Icones'
import { Bouton } from './Bouton'
import { useConfirmer } from '@/lib/confirmation'
import { useUtilisateurCourant } from '@/lib/use-permissions'
import { structuresDetailQueryOptions } from '@/lib/parties-prenantes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

const schema = z.object({
  prenom: z.string().min(1, 'Le prénom est requis.'),
  nom: z.string().min(1, 'Le nom est requis.'),
  fonction: z.string().optional(),
  structureUuid: z.string().optional(),
  actif: z.boolean(),
})

type FormValues = z.infer<typeof schema>

interface ModifierUtilisateurModaleProps {
  utilisateur: Utilisateur
  onFermer: () => void
}

/** Édition d'un compte de connexion — email non modifiable ici (identifiant de connexion, § 4.5). */
export function ModifierUtilisateurModale({ utilisateur, onFermer }: ModifierUtilisateurModaleProps) {
  const { data: structures } = useQuery(structuresDetailQueryOptions())
  const { data: rolesDisponibles } = useQuery(rolesQueryOptions())
  const [erreur, setErreur] = useState<string | null>(null)
  // Rôles du compte, modifiables : un rôle et sa portée (vide = toutes les structures).
  const [attributions, setAttributions] = useState(utilisateur.roles.map((r) => ({ role: r.role, structure: r.structure ?? '' })))
  const rolesModifies =
    JSON.stringify(attributions) !== JSON.stringify(utilisateur.roles.map((r) => ({ role: r.role, structure: r.structure ?? '' })))

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      prenom: utilisateur.prenom,
      nom: utilisateur.nom,
      fonction: utilisateur.fonction ?? '',
      structureUuid: utilisateur.structure?.uuid ?? '',
      actif: utilisateur.actif,
    },
  })

  async function onSubmit(valeurs: FormValues) {
    setErreur(null)
    try {
      await modifierUtilisateur(utilisateur.uuid, {
        prenom: valeurs.prenom,
        nom: valeurs.nom,
        fonction: valeurs.fonction || null,
        structureUuid: valeurs.structureUuid || null,
        actif: valeurs.actif,
      })
      if (rolesModifies) {
        await definirRolesUtilisateur(
          utilisateur.uuid,
          attributions.filter((a) => a.role !== '').map((a) => ({ role: a.role, structure: a.structure || null })),
        )
      }
      await queryClient.invalidateQueries({ queryKey: ['admin', 'utilisateurs'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    }
  }

  return (
    <Modale titre={`Modifier ${utilisateur.prenom} ${utilisateur.nom}`} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
        <p className="text-sm text-muted">{utilisateur.email}</p>

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
          <label htmlFor="fonction">Fonction (optionnel)</label>
          <input id="fonction" className="rounded-[var(--radius-controle)] border border-border px-3 py-2" {...register('fonction')} />
        </div>

        <div className="flex flex-col gap-1">
          <label htmlFor="structureUuid">Structure (optionnel)</label>
          <select
            id="structureUuid"
            className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            {...register('structureUuid')}
          >
            <option value="">Aucune</option>
            {structures?.donnees.map((s) => (
              <option key={s.uuid} value={s.uuid}>
                {s.sigle}
              </option>
            ))}
          </select>
        </div>

        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1">Rôles</legend>
          {attributions.length === 0 && <p className="text-sm text-muted">Aucun rôle : le compte ne voit rien.</p>}
          {attributions.map((a, index) => (
            <div key={index} className="flex flex-wrap items-center gap-2">
              <select
                aria-label={`Rôle ${index + 1}`}
                value={a.role}
                onChange={(e) => setAttributions(attributions.map((x, i) => (i === index ? { ...x, role: e.target.value } : x)))}
                className="min-w-0 flex-1 rounded-[var(--radius-controle)] border border-border px-3 py-2"
              >
                <option value="">Choisir un rôle…</option>
                {rolesDisponibles?.donnees.map((r) => (
                  <option key={r.uuid} value={r.code}>
                    {r.libelle}
                  </option>
                ))}
              </select>
              <select
                aria-label={`Portée du rôle ${index + 1}`}
                value={a.structure}
                onChange={(e) => setAttributions(attributions.map((x, i) => (i === index ? { ...x, structure: e.target.value } : x)))}
                className="w-40 rounded-[var(--radius-controle)] border border-border px-3 py-2"
              >
                <option value="">Toutes les structures</option>
                {structures?.donnees.map((s) => (
                  <option key={s.uuid} value={s.uuid}>
                    {s.sigle}
                  </option>
                ))}
              </select>
              <IconeBouton
                libelle="Retirer ce rôle"
                onClick={() => setAttributions(attributions.filter((_, i) => i !== index))}
                classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
              >
                <IconeAnnuler />
              </IconeBouton>
            </div>
          ))}
          <button
            type="button"
            onClick={() => setAttributions([...attributions, { role: '', structure: '' }])}
            className="flex items-center gap-1.5 self-start text-sm font-semibold text-accent hover:text-accent-d"
          >
            <IconePlus />
            Ajouter un rôle
          </button>
          <p className="text-xs text-muted">
            Portée : une structure limite le rôle à ses activités ; « Toutes les structures » l'ouvre sur tout le projet.
          </p>
        </fieldset>

        <label className="flex items-center gap-2">
          <input type="checkbox" {...register('actif')} />
          Compte actif
        </label>

        <MotDePasseTemporaire utilisateur={utilisateur} />

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

/**
 * Mot de passe oublié : l'écran de connexion renvoie vers l'administrateur.
 * Le nouveau mot de passe part par courriel et s'affiche ici une seule
 * fois ; il devra être changé à la connexion suivante. Absent sur son
 * propre compte, qui se gère depuis « Mon profil ».
 */
function MotDePasseTemporaire({ utilisateur }: { utilisateur: Utilisateur }) {
  const { data: moi } = useUtilisateurCourant()
  const confirmer = useConfirmer()
  const [resultat, setResultat] = useState<{ motDePasseTemporaire: string; courrielEnvoye: boolean } | null>(null)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)
  const [copie, setCopie] = useState(false)

  if (moi?.uuid === utilisateur.uuid) return null

  async function attribuer() {
    if (
      !(await confirmer({
        titre: 'Nouveau mot de passe temporaire',
        message: `Le mot de passe actuel de ${utilisateur.prenom} ${utilisateur.nom} cessera aussitôt de fonctionner. Le nouveau lui sera envoyé par courriel, et devra être changé à sa prochaine connexion.`,
        action: 'Attribuer',
      }))
    ) {
      return
    }
    setErreur(null)
    setEnCours(true)
    try {
      setResultat(await attribuerMotDePasseTemporaire(utilisateur.uuid))
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <fieldset className="flex flex-col gap-2 rounded-[var(--radius-controle)] border border-border p-3">
      <legend className="px-1 text-sm font-semibold">Mot de passe</legend>
      {erreur && <p className="text-sm text-bad">{erreur}</p>}
      {resultat ? (
        <>
          <p className="text-sm">
            {resultat.courrielEnvoye
              ? 'Le mot de passe temporaire vient de lui être envoyé par courriel. Il ne sera plus jamais affiché ici.'
              : "Le courriel n'a pas pu partir : communiquez ce mot de passe hors-bande (téléphone, en main propre…). Il ne sera plus jamais affiché."}
          </p>
          <div className="flex items-center justify-between gap-3 rounded-[var(--radius-controle)] border border-border bg-nested px-3 py-2">
            <span className="chiffres select-all break-all">{resultat.motDePasseTemporaire}</span>
            <Bouton
              variante="discret"
              taille="mini"
              icone={copie ? <IconeValider /> : <IconeCopier />}
              onClick={async () => {
                await navigator.clipboard.writeText(resultat.motDePasseTemporaire)
                setCopie(true)
              }}
            >
              {copie ? 'Copié' : 'Copier'}
            </Bouton>
          </div>
        </>
      ) : (
        <>
          <p className="text-xs text-muted">En cas d'oubli : l'ancien mot de passe cesse de fonctionner, le nouveau est à changer à la connexion.</p>
          <Bouton variante="secondaire" taille="mini" disabled={enCours} onClick={() => void attribuer()} className="self-start">
            {enCours ? 'Attribution…' : 'Nouveau mot de passe temporaire'}
          </Bouton>
        </>
      )}
    </fieldset>
  )
}
