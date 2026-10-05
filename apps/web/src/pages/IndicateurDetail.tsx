import { useState } from 'react'
import { useParams, Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import {
  indicateurQueryOptions,
  indicateurValeursQueryOptions,
  invitationsQueryOptions,
  revoquerInvitation,
  validerValeur,
} from '@/lib/indicateurs'
import { formaterDate, formaterValeurIndicateur } from '@/lib/format'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { usePeut } from '@/lib/use-permissions'
import { usePeriodeFiltre } from '@/lib/periode'
import { urlTelechargerPieceJointe } from '@/lib/exports'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { BarresObjectifsIndicateur } from '@/components/BarresObjectifsIndicateur'
import { CourbeIndicateur } from '@/components/CourbeIndicateur'
import { Pastille } from '@/components/Pastille'
import { SaisirValeurIndicateurModale } from '@/components/SaisirValeurIndicateurModale'
import { InviterSectorielModale } from '@/components/InviterSectorielModale'
import { SelecteurPeriode } from '@/components/SelecteurPeriode'
import { Squelette } from '@/components/Squelette'
import { useToast } from '@/components/ToastProvider'
import { Bouton } from '@/components/Bouton'
import { IconeBouton, IconeTrombone, IconeAnnuler, IconePlus, IconeInviter, IconeValider } from '@/components/Icones'

function formaterTaille(octets: number): string {
  if (octets < 1024) return `${octets} o`
  if (octets < 1024 * 1024) return `${Math.round(octets / 1024)} Ko`
  return `${(octets / (1024 * 1024)).toFixed(1)} Mo`
}

export function IndicateurDetail() {
  const { uuid } = useParams<{ uuid: string }>()
  const { data: indicateur, isLoading, isError } = useQuery(indicateurQueryOptions(uuid))
  const { data: valeurs } = useQuery(indicateurValeursQueryOptions(uuid))
  const peutInviter = usePeut('indicateurs.inviter')
  const { data: invitations } = useQuery({ ...invitationsQueryOptions(uuid), enabled: peutInviter })
  const { correspond: correspondAPeriode } = usePeriodeFiltre()

  const [modaleOuverte, setModaleOuverte] = useState(false)
  const [modaleInvitationOuverte, setModaleInvitationOuverte] = useState(false)
  const [actionEnCours, setActionEnCours] = useState<string | null>(null)
  const [erreurAction, setErreurAction] = useState<string | null>(null)

  const peutValider = usePeut('indicateurs.valider')
  const afficherToast = useToast()

  if (isLoading) return <Squelette lignes={6} />
  if (isError || !indicateur) {
    return (
      <div>
        <p className="text-bad">Indicateur introuvable.</p>
        <Link to="/indicateurs" className="text-accent">
          Retour aux indicateurs de performance
        </Link>
      </div>
    )
  }

  const pointsCourbe = (valeurs?.donnees ?? [])
    .filter((v) => v.statut === 'valide')
    .map((v) => ({ periode: v.periode, valeur: Number(v.valeur) }))

  const valeursFiltrees = (valeurs?.donnees ?? []).filter((v) => correspondAPeriode(v.periode))

  async function executerValidation(valeurUuid: string) {
    setErreurAction(null)
    setActionEnCours(valeurUuid)
    try {
      await validerValeur(valeurUuid)
      await queryClient.invalidateQueries({ queryKey: ['indicateur', uuid] })
      afficherToast('Valeur validée.')
    } catch (e) {
      setErreurAction(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setActionEnCours(null)
    }
  }

  async function executerRevocation(invitationUuid: string) {
    setErreurAction(null)
    setActionEnCours(invitationUuid)
    try {
      await revoquerInvitation(invitationUuid)
      await queryClient.invalidateQueries({ queryKey: ['indicateur', uuid, 'invitations'] })
      afficherToast('Invitation révoquée.')
    } catch (e) {
      setErreurAction(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setActionEnCours(null)
    }
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Link to="/indicateurs" className="text-sm text-accent">
          ← Indicateurs de performance
        </Link>
        <div className="mt-2 flex items-center gap-3">
          <span className="chiffres text-muted">{indicateur.code}</span>
          <span className="rounded-full bg-accent-bg px-2 py-0.5 text-xs font-semibold text-accent">{indicateur.type}</span>
        </div>
        <h1 className="text-2xl">{indicateur.libelle}</h1>
        {indicateur.precisionLibelle && <p className="text-muted">{indicateur.precisionLibelle}</p>}
        <p className="mt-2 text-sm text-muted">
          Source : {indicateur.source} · Fréquence : {indicateur.frequence}
          {indicateur.composante && ` · Composante ${indicateur.composante.code}`}
        </p>
      </div>

      {erreurAction && (
        <div role="alert" className="rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreurAction}
        </div>
      )}

      <section>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-semibold">Progression</h2>
          {indicateur.peutSaisir && (
            <Bouton onClick={() => setModaleOuverte(true)} icone={<IconePlus />}>
              Saisir une valeur
            </Bouton>
          )}
        </div>
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-5">
          <div>
            <dt className="text-muted text-sm">Référence ({formaterDate(indicateur.dateReference)})</dt>
            <dd className="chiffres text-lg">
              {formaterValeurIndicateur(indicateur.valeurReference)} {indicateur.unite}
            </dd>
          </div>
          <div>
            <dt className="text-muted text-sm">Actuel {indicateur.periodeActuelle && `(${formaterDate(indicateur.periodeActuelle)})`}</dt>
            <dd className="chiffres text-lg">
              {indicateur.actuel === null ? '—' : `${formaterValeurIndicateur(indicateur.actuel)} ${indicateur.unite}`}
            </dd>
          </div>
          {indicateur.valeurIntermediaire !== null && (
            <div>
              <dt className="text-muted text-sm">
                Objectif intermédiaire {indicateur.dateIntermediaire && `(${formaterDate(indicateur.dateIntermediaire)})`}
              </dt>
              <dd className="chiffres text-lg">
                {formaterValeurIndicateur(indicateur.valeurIntermediaire)} {indicateur.unite}
              </dd>
            </div>
          )}
          <div>
            <dt className="text-muted text-sm">Cible ({formaterDate(indicateur.dateCible)})</dt>
            <dd className="chiffres text-lg">
              {formaterValeurIndicateur(indicateur.valeurCible)} {indicateur.unite}
            </dd>
          </div>
          <div>
            <dt className="text-muted text-sm">Taux d'atteinte</dt>
            <dd className="mt-1">
              <BarreAtteinte taux={indicateur.tauxAtteinte} />
            </dd>
          </div>
        </dl>

        <div className="mt-4 rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
          <BarresObjectifsIndicateur
            valeurReference={indicateur.valeurReference}
            actuel={indicateur.actuel}
            valeurIntermediaire={indicateur.valeurIntermediaire}
            valeurCible={indicateur.valeurCible}
            unite={indicateur.unite}
          />
        </div>
      </section>

      <section>
        <h2 className="mb-2 text-lg font-semibold">Évolution</h2>
        <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
          <CourbeIndicateur points={pointsCourbe} unite={indicateur.unite} tauxAtteinte={indicateur.tauxAtteinte} />
        </div>
      </section>

      {peutInviter && (
        <section>
          <div className="mb-2 flex items-center justify-between">
            <h2 className="text-lg font-semibold">Accès</h2>
            <Bouton onClick={() => setModaleInvitationOuverte(true)} icone={<IconeInviter />}>
              Inviter un sectoriel
            </Bouton>
          </div>
          <p className="mb-2 text-sm text-muted">
            Ouvre l'accès à cet indicateur (consultation et saisie) à un sectoriel, sans lui accorder les permissions globales
            du module.
          </p>
          {invitations && invitations.donnees.length > 0 ? (
            <ul className="flex flex-col gap-2">
              {invitations.donnees.map((inv) => (
                <li
                  key={inv.uuid}
                  className="flex items-center justify-between gap-3 rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]"
                >
                  <span className="text-sm">
                    <span className="font-semibold">{inv.utilisateur.nomComplet}</span>
                    <span className="text-muted"> — invité par {inv.invitePar} le {formaterDate(inv.inviteLe)}</span>
                  </span>
                  <IconeBouton
                    libelle="Révoquer l'invitation"
                    disabled={actionEnCours === inv.uuid}
                    onClick={() => executerRevocation(inv.uuid)}
                    classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                  >
                    <IconeAnnuler />
                  </IconeBouton>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted">Aucun sectoriel invité pour le moment.</p>
          )}
        </section>
      )}

      <section>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-semibold">Série historique</h2>
          <SelecteurPeriode />
        </div>
        {valeurs && valeursFiltrees.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {[...valeursFiltrees].reverse().map((v) => (
              <li key={v.uuid} className="rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
                <div className="flex items-center justify-between">
                  <span>
                    <span className="chiffres mr-2">{formaterDate(v.periode)}</span>
                    <span className="chiffres font-semibold">
                      {formaterValeurIndicateur(v.valeur)} {indicateur.unite}
                    </span>
                  </span>
                  <div className="flex items-center gap-3">
                    <Pastille statut={v.statut} />
                    {v.statut === 'brouillon' && peutValider && (
                      <Bouton
                        variante="discret"
                        taille="mini"
                        icone={<IconeValider />}
                        disabled={actionEnCours === v.uuid}
                        onClick={() => executerValidation(v.uuid)}
                      >
                        Valider
                      </Bouton>
                    )}
                  </div>
                </div>
                {v.commentaire && <p className="mt-1 text-sm text-muted">{v.commentaire}</p>}
                {v.desagregations.length > 0 && (
                  <ul className="mt-2 ml-4 flex flex-col gap-1 border-l border-border pl-3 text-sm text-muted">
                    {v.desagregations.map((d, i) => (
                      <li key={i}>
                        {d.dimension} — {d.modalite} :{' '}
                        <span className="chiffres">
                          {formaterValeurIndicateur(d.valeur)} {indicateur.unite}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
                {v.piecesJointes.length > 0 && (
                  <ul className="mt-2 flex flex-col gap-1 text-sm">
                    {v.piecesJointes.map((p) => (
                      <li key={p.uuid} className="flex items-center gap-2">
                        <IconeTrombone />
                        <a href={urlTelechargerPieceJointe(p.uuid)} className="min-w-0 truncate text-accent hover:text-accent-d">
                          {p.nomFichier}
                        </a>
                        <span className="chiffres shrink-0 text-xs text-muted">{formaterTaille(p.tailleOctets)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-muted">
            {valeurs && valeurs.donnees.length > 0 ? 'Aucune valeur pour cette période.' : 'Aucune valeur saisie pour le moment.'}
          </p>
        )}
      </section>

      {modaleInvitationOuverte && (
        <InviterSectorielModale indicateurUuid={indicateur.uuid} onFermer={() => setModaleInvitationOuverte(false)} />
      )}

      {modaleOuverte && indicateur && (
        <SaisirValeurIndicateurModale indicateurUuid={indicateur.uuid} onFermer={() => setModaleOuverte(false)} />
      )}
    </div>
  )
}
