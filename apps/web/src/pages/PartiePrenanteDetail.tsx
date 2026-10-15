import { useRef, useState } from 'react'
import { useParams, Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import {
  structureQueryOptions,
  structureActivitesQueryOptions,
  pointsFocauxQueryOptions,
  rapportsQueryOptions,
  transmettreRapport,
  lierCompteUtilisateur,
  type Rapport,
  type PointFocal,
} from '@/lib/parties-prenantes'
import { LIBELLES_TYPE_STRUCTURE } from '@/lib/types-structure'
import { queryClient } from '@/lib/query-client'
import { formaterDate } from '@/lib/format'
import { indicateursQueryOptions } from '@/lib/indicateurs'
import { Pastille } from '@/components/Pastille'
import { BarreAtteinte } from '@/components/BarreAtteinte'
import { LierCompteModale } from '@/components/LierCompteModale'
import { ApiError } from '@/lib/api-client'
import { usePeut } from '@/lib/use-permissions'
import { Squelette } from '@/components/Squelette'
import { useToast } from '@/components/ToastProvider'
import { Bouton } from '@/components/Bouton'
import { IconeLier, IconeAnnuler, IconeTransmettre } from '@/components/Icones'
import { useConfirmer } from '@/lib/confirmation'

const LIBELLES_STATUT_RAPPORT: Record<Rapport['statutAffiche'], string> = {
  attendu: 'Attendu',
  transmis: 'Transmis',
  valide: 'Validé',
  rejete: 'Rejeté',
  retard: 'En retard',
}

export function PartiePrenanteDetail() {
  const confirmer = useConfirmer()
  const { uuid } = useParams<{ uuid: string }>()
  const { data: structure, isLoading, isError } = useQuery(structureQueryOptions(uuid))
  const { data: activites } = useQuery(structureActivitesQueryOptions(uuid))
  const { data: pointsFocaux } = useQuery(pointsFocauxQueryOptions(uuid))
  const { data: rapports } = useQuery(rapportsQueryOptions())
  const peutGererPointsFocaux = usePeut('points_focaux.gerer')
  const peutSaisirIndicateur = usePeut('indicateurs.saisir')
  // Module réservé à l'équipe PHASAOC (§ décision) : un sectoriel consultant
  // sa propre fiche structure ne doit plus voir cette section.
  const voitIndicateurs = usePeut('indicateurs.consulter')
  const { data: indicateurs } = useQuery({ ...indicateursQueryOptions(undefined, undefined, uuid), enabled: voitIndicateurs })
  const afficherToast = useToast()

  const [rapportEnCoursTransmission, setRapportEnCoursTransmission] = useState<string | null>(null)
  const [erreurTransmission, setErreurTransmission] = useState<string | null>(null)
  const inputFichierRef = useRef<HTMLInputElement>(null)
  const [pointFocalALier, setPointFocalALier] = useState<PointFocal | null>(null)
  const [erreurLiaison, setErreurLiaison] = useState<string | null>(null)
  const [delierEnCours, setDelierEnCours] = useState<string | null>(null)

  if (isLoading) return <Squelette lignes={6} />
  if (isError || !structure) {
    return (
      <div>
        <p className="text-bad">Structure introuvable.</p>
        <Link to="/parties-prenantes" className="text-accent">
          Retour aux parties prenantes
        </Link>
      </div>
    )
  }

  const rapportsStructure = (rapports?.donnees ?? []).filter((r) => r.structure.uuid === structure.uuid)

  async function delier(pointFocal: PointFocal) {
    setErreurLiaison(null)
    setDelierEnCours(pointFocal.uuid)
    try {
      await lierCompteUtilisateur(pointFocal.uuid, null)
      await queryClient.invalidateQueries({ queryKey: ['points-focaux'] })
    } catch (e) {
      setErreurLiaison(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setDelierEnCours(null)
    }
  }

  async function transmettre(rapport: Rapport, fichier: File) {
    setErreurTransmission(null)
    setRapportEnCoursTransmission(rapport.uuid)
    try {
      await transmettreRapport(rapport.uuid, fichier)
      await queryClient.invalidateQueries({ queryKey: ['rapports'] })
      afficherToast('Rapport transmis.')
    } catch (e) {
      setErreurTransmission(e instanceof Error ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setRapportEnCoursTransmission(null)
    }
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Link to="/parties-prenantes" className="text-sm text-accent">
          ← Parties prenantes
        </Link>
        <p className="mt-2 text-[10px] font-semibold uppercase tracking-[.14em] text-eyebrow">
          {LIBELLES_TYPE_STRUCTURE[structure.type]}
        </p>
        <h1 className="text-2xl">{structure.sigle}</h1>
        <p className="text-muted">{structure.denomination}</p>
        <p className="mt-2 text-sm text-muted">
          {structure.telephone && <>Tél. <span className="chiffres">{structure.telephone}</span></>}
          {structure.telephone && structure.email && ' · '}
          {structure.email}
        </p>
      </div>

      <section>
        <h2 className="mb-3 text-lg font-semibold">Points focaux</h2>
        {erreurLiaison && (
          <div role="alert" className="mb-3 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
            {erreurLiaison}
          </div>
        )}
        {pointsFocaux && pointsFocaux.donnees.length > 0 ? (
          <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {pointsFocaux.donnees.map((p) => (
              <li key={p.uuid} className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
                <p className="font-semibold">
                  {p.nomComplet}
                  {p.principal && <span className="ml-2 rounded-full bg-accent-bg px-2 py-0.5 text-xs text-accent">Principal</span>}
                </p>
                <p className="text-sm text-muted">{p.fonction}</p>
                <p className="chiffres mt-1 text-xs text-muted">{p.telephone ?? p.email ?? '—'}</p>
                {peutGererPointsFocaux && (
                  <div className="mt-2 flex flex-wrap items-center gap-2 border-t border-border pt-2 text-xs">
                    {p.utilisateur ? (
                      <>
                        <span className="text-muted">Compte lié : {p.utilisateur.email}</span>
                        <Bouton
                          variante="danger"
                          taille="mini"
                          icone={<IconeAnnuler />}
                          disabled={delierEnCours === p.uuid}
                          onClick={async () => {
                        if (await confirmer({ titre: 'Délier le compte', message: `${p.nomComplet} ne sera plus rattaché à son compte utilisateur. Le compte lui-même n'est pas supprimé.`, action: 'Délier' })) {
                          void delier(p)
                        }
                      }}
                        >
                          {delierEnCours === p.uuid ? 'Déliaison…' : 'Délier'}
                        </Bouton>
                      </>
                    ) : (
                      <Bouton variante="discret" taille="mini" icone={<IconeLier />} onClick={() => setPointFocalALier(p)}>
                        Lier un compte
                      </Bouton>
                    )}
                  </div>
                )}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-muted">{peutGererPointsFocaux ? 'Aucun point focal désigné.' : 'Aucun point focal désigné pour le moment.'}</p>
        )}
      </section>

      <section>
        <h2 className="mb-3 text-lg font-semibold">Reporting trimestriel</h2>
        {erreurTransmission && (
          <div role="alert" className="mb-3 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
            {erreurTransmission}
          </div>
        )}
        {rapportsStructure.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {rapportsStructure.map((r) => (
              <li
                key={r.uuid}
                className="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]"
              >
                <div>
                  <span className="chiffres mr-2 text-muted">
                    {r.periode} {r.exercice}
                  </span>
                  <span className="text-sm text-muted">Échéance {formaterDate(r.dateEcheance)}</span>
                </div>
                <div className="flex items-center gap-3">
                  <Pastille statut={r.statutAffiche === 'retard' ? 'annule' : r.statut} />
                  <span className="text-xs text-muted">{LIBELLES_STATUT_RAPPORT[r.statutAffiche]}</span>
                  {r.pieceJointe && (
                    <span className="text-xs text-muted" title={r.pieceJointe.nomFichier}>
                      📎 {r.pieceJointe.nomFichier}
                    </span>
                  )}
                  {'attendu' === r.statut && (
                    <>
                      <input
                        ref={inputFichierRef}
                        type="file"
                        className="hidden"
                        onChange={(e) => {
                          const fichier = e.target.files?.[0]
                          if (fichier) void transmettre(r, fichier)
                          e.target.value = ''
                        }}
                      />
                      <Bouton
                        variante="discret"
                        taille="mini"
                        icone={<IconeTransmettre />}
                        disabled={rapportEnCoursTransmission === r.uuid}
                        onClick={() => inputFichierRef.current?.click()}
                      >
                        {rapportEnCoursTransmission === r.uuid ? 'Envoi…' : 'Transmettre'}
                      </Bouton>
                    </>
                  )}
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-muted">Aucun rapport pour le moment.</p>
        )}
      </section>

      <section>
        <h2 className="mb-3 text-lg font-semibold">Activités pilotées</h2>
        {activites && activites.donnees.length > 0 ? (
          <ul className="flex flex-col gap-2">
            {activites.donnees.map((a) => (
              <li key={a.uuid} className="rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
                <Link to={`/composantes/activites/${a.uuid}`} className="flex items-center justify-between">
                  <span>
                    <span className="chiffres mr-2 text-muted">{a.code}</span>
                    {a.libelle}
                  </span>
                  <Pastille statut={a.statut} />
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-muted">Aucune activité pilotée par cette structure.</p>
        )}
      </section>

      {voitIndicateurs && (
        <section>
          <h2 className="mb-1 text-lg font-semibold">Indicateurs de performance dont la structure est responsable</h2>
          {peutSaisirIndicateur && (
            <p className="mb-3 text-sm text-muted">Ouvrez un indicateur pour y saisir sa valeur périodique.</p>
          )}
          {indicateurs && indicateurs.donnees.length > 0 ? (
            <ul className="flex flex-col gap-3">
              {indicateurs.donnees.map((i) => (
                <li key={i.uuid} className="rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
                  <Link to={`/indicateurs/${i.uuid}`} className="mb-1 block truncate">
                    <span className="chiffres mr-2 text-muted">{i.code}</span>
                    {i.libelle}
                  </Link>
                  <BarreAtteinte taux={i.tauxAtteinte} />
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-muted">Aucun indicateur du cadre de résultats n'est rattaché à cette structure.</p>
          )}
        </section>
      )}

      {pointFocalALier && <LierCompteModale pointFocal={pointFocalALier} onFermer={() => setPointFocalALier(null)} />}
    </div>
  )
}
