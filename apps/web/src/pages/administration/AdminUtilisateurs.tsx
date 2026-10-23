import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { utilisateursQueryOptions, modifierUtilisateur, supprimerUtilisateur, type Utilisateur } from '@/lib/utilisateurs'
import { usePeut } from '@/lib/use-permissions'
import { queryClient } from '@/lib/query-client'
import { ApiError } from '@/lib/api-client'
import { AdminOnglets } from '@/components/AdminOnglets'
import { CreerUtilisateurModale } from '@/components/CreerUtilisateurModale'
import { ModifierUtilisateurModale } from '@/components/ModifierUtilisateurModale'
import { IconeBouton, IconeModifier, IconeRecherche, IconePlus, IconeValider, IconeAnnuler, IconeSupprimer } from '@/components/Icones'
import { Pagination } from '@/components/Pagination'
import { Squelette } from '@/components/Squelette'
import { useToast } from '@/components/ToastProvider'
import { trancherPage } from '@/lib/pagination'
import { formaterDate } from '@/lib/format'
import { useConfirmer } from '@/lib/confirmation'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'
import { rolesQueryOptions } from '@/lib/admin'
import { structuresDetailQueryOptions } from '@/lib/parties-prenantes'

const TAILLE_PAGE = 10

export function AdminUtilisateurs() {
  const confirmer = useConfirmer()
  const { data: utilisateurs, isLoading, isError } = useQuery(utilisateursQueryOptions())
  const { data: rolesDisponibles } = useQuery(rolesQueryOptions())
  const { data: structuresConnues } = useQuery(structuresDetailQueryOptions())
  const libellesRoles = new Map((rolesDisponibles?.donnees ?? []).map((r) => [r.code, r.libelle]))
  const siglesStructures = new Map((structuresConnues?.donnees ?? []).map((st) => [st.uuid, st.sigle]))
  const peutGerer = usePeut('admin.utilisateurs')
  const afficherToast = useToast()

  const [searchParams, setSearchParams] = useSearchParams()
  const [modaleCreation, setModaleCreation] = useState(false)
  const [utilisateurEnEdition, setUtilisateurEnEdition] = useState<Utilisateur | null>(null)
  const [erreur, setErreur] = useState<string | null>(null)
  const [enCours, setEnCours] = useState<string | null>(null)

  const recherche = searchParams.get('q') ?? ''
  const page = Math.max(1, Number(searchParams.get('page') ?? '1'))

  function definirRecherche(valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') {
      suivant.delete('q')
    } else {
      suivant.set('q', valeur)
    }
    suivant.delete('page')
    setSearchParams(suivant, { replace: true })
  }

  function definirPage(suivante: number) {
    const suivant = new URLSearchParams(searchParams)
    suivant.set('page', String(suivante))
    setSearchParams(suivant, { replace: true })
  }

  const utilisateursFiltres = useMemo(() => {
    const rechercheLC = recherche.trim().toLowerCase()
    if (rechercheLC === '') return utilisateurs?.donnees ?? []

    return (utilisateurs?.donnees ?? []).filter(
      (u) =>
        u.email.toLowerCase().includes(rechercheLC) ||
        u.nom.toLowerCase().includes(rechercheLC) ||
        u.prenom.toLowerCase().includes(rechercheLC),
    )
  }, [utilisateurs, recherche])

  const totalPages = Math.max(1, Math.ceil(utilisateursFiltres.length / TAILLE_PAGE))
  const utilisateursPage = trancherPage(utilisateursFiltres, page, TAILLE_PAGE)

  async function basculerActif(u: Utilisateur) {
    setErreur(null)
    setEnCours(u.uuid)
    try {
      await modifierUtilisateur(u.uuid, { actif: !u.actif })
      await queryClient.invalidateQueries({ queryKey: ['admin', 'utilisateurs'] })
      afficherToast(`Compte de ${u.prenom} ${u.nom} ${u.actif ? 'désactivé' : 'activé'}.`)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(null)
    }
  }

  async function supprimer(u: Utilisateur) {
    setErreur(null)
    setEnCours(u.uuid)
    try {
      await supprimerUtilisateur(u.uuid)
      await queryClient.invalidateQueries({ queryKey: ['admin', 'utilisateurs'] })
      afficherToast(`Compte de ${u.prenom} ${u.nom} supprimé.`)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(null)
    }
  }

  return (
    <div>
      <p className="font-titre text-eyebrow uppercase tracking-wide">Backoffice</p>
      <h1 className="text-2xl">Administration</h1>
      <p className="mb-6 text-muted">Référentiels, parties prenantes, rôles et permissions, journal des actions.</p>

      <AdminOnglets />

      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold">Comptes utilisateurs</h2>
        <div className="flex flex-wrap items-center gap-2">
        {peutGerer && <BoutonExporter href={urlExportDonnees('comptes')} libelle="Exporter" />}
        {peutGerer && (
          <button
            type="button"
            onClick={() => setModaleCreation(true)}
            className="flex items-center gap-1.5 rounded-[var(--radius-controle)] bg-accent px-4 py-2 font-semibold text-white transition-colors hover:bg-accent-d"
          >
            <IconePlus />
            Ajouter un compte
          </button>
        )}
        </div>
      </div>

      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <div className="mb-4 flex flex-wrap gap-3" role="search" aria-label="Filtres des comptes utilisateurs">
        <div className="relative min-w-48 flex-1">
          <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted">
            <IconeRecherche />
          </span>
          <input
            type="search"
            aria-label="Rechercher un compte"
            placeholder="Rechercher un nom ou un email…"
            value={recherche}
            onChange={(e) => definirRecherche(e.target.value)}
            className="w-full rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          />
        </div>
      </div>

      {isLoading && <Squelette lignes={4} />}
      {isError && <p className="text-bad">Impossible de charger les comptes. Réessayez.</p>}

      {utilisateurs && (
        <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left text-muted">
                <th className="px-4 py-3 font-normal">Nom</th>
                <th className="px-4 py-3 font-normal">Email</th>
                <th className="px-4 py-3 font-normal">Structure</th>
                <th className="px-4 py-3 font-normal">Rôles</th>
                <th className="px-4 py-3 font-normal">Statut</th>
                <th className="px-4 py-3 font-normal">Dernière connexion</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {utilisateursPage.map((u) => (
                <tr key={u.uuid} className="border-b border-border last:border-0 hover:bg-hover">
                  <td className="px-4 py-3">
                    <span className="font-semibold">
                      {u.prenom} {u.nom}
                    </span>
                    {u.fonction && <p className="text-xs text-muted">{u.fonction}</p>}
                  </td>
                  <td className="px-4 py-3">{u.email}</td>
                  <td className="px-4 py-3">{u.structure?.sigle ?? '—'}</td>
                  <td className="px-4 py-3 text-sm">
                    {u.roles.length === 0 ? (
                      <span className="text-muted">Aucun</span>
                    ) : (
                      u.roles.map((r) => (
                        <span key={`${r.role}-${r.structure ?? ''}`} className="block">
                          {libellesRoles.get(r.role) ?? r.role}
                          {r.structure && <span className="text-muted"> ({siglesStructures.get(r.structure) ?? '…'})</span>}
                        </span>
                      ))
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${
                        u.actif ? 'bg-ok-bg text-ok' : 'bg-nested text-muted'
                      }`}
                    >
                      <span className="h-1.5 w-1.5 rounded-full bg-current" />
                      {u.actif ? 'Actif' : 'Inactif'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-muted">{u.derniereConnexion ? formaterDate(u.derniereConnexion) : 'Jamais connecté'}</td>
                  <td className="px-4 py-3 text-right">
                    {peutGerer && (
                      <div className="flex justify-end gap-1">
                        <IconeBouton
                          libelle={`Modifier le compte de ${u.prenom} ${u.nom}`}
                          onClick={() => setUtilisateurEnEdition(u)}
                          classeCouleur="text-muted hover:bg-accent-bg hover:text-accent"
                        >
                          <IconeModifier />
                        </IconeBouton>
                        <IconeBouton
                          libelle={u.actif ? `Désactiver le compte de ${u.prenom} ${u.nom}` : `Activer le compte de ${u.prenom} ${u.nom}`}
                          disabled={enCours === u.uuid}
                          onClick={() => basculerActif(u)}
                          classeCouleur={u.actif ? 'text-muted hover:bg-warn-bg hover:text-warn' : 'text-muted hover:bg-ok-bg hover:text-ok'}
                        >
                          {u.actif ? <IconeAnnuler /> : <IconeValider />}
                        </IconeBouton>
                        <IconeBouton
                          libelle={`Supprimer le compte de ${u.prenom} ${u.nom}`}
                          disabled={enCours === u.uuid}
                          onClick={async () => {
                        if (await confirmer({ titre: 'Supprimer le compte', message: `Le compte de ${u.prenom} ${u.nom} sera supprimé : la personne ne pourra plus se connecter. Pour une suspension temporaire, désactivez plutôt le compte.`, action: 'Supprimer' })) {
                          void supprimer(u)
                        }
                      }}
                          classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                        >
                          <IconeSupprimer />
                        </IconeBouton>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
              {utilisateursPage.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-muted">
                    Aucun compte ne correspond à ces filtres.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
          <Pagination page={page} totalPages={totalPages} total={utilisateursFiltres.length} taille={TAILLE_PAGE} onChangerPage={definirPage} />
        </div>
      )}

      {modaleCreation && <CreerUtilisateurModale onFermer={() => setModaleCreation(false)} />}
      {utilisateurEnEdition && (
        <ModifierUtilisateurModale utilisateur={utilisateurEnEdition} onFermer={() => setUtilisateurEnEdition(null)} />
      )}
    </div>
  )
}
