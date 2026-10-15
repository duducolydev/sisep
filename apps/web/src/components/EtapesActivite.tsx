import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bouton } from './Bouton'
import { IconeBouton, IconeAnnuler, IconeModifier, IconePlus, IconeSupprimer, IconeTrombone } from './Icones'
import { RenseignerEtapeModale } from './RenseignerEtapeModale'
import {
  ajouterEtape,
  etapesActiviteQueryOptions,
  gabaritsEtapesQueryOptions,
  instancierGabarit,
  modifierLivrable,
  creerLivrable,
  supprimerEtape,
  supprimerLivrable,
  televerserPieceJointeEtape,
  type EtapeActivite,
} from '@/lib/etapes'
import { urlTelechargerPieceJointe } from '@/lib/exports'
import { formaterDate, formaterTaux } from '@/lib/format'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { useConfirmer } from '@/lib/confirmation'
import { JoindreFichierModale } from './JoindreFichierModale'

interface EtapesActiviteProps {
  activiteUuid: string
  /** Étapes d'une ligne du PTBA (sous-activité) plutôt que les étapes propres de l'activité. */
  sousActiviteUuid?: string
  /** Intitulé de la section ; absent, la section n'a pas de titre (étapes d'une ligne, déjà titrée par sa carte). */
  titre?: string
  /** Structurer : instancier un gabarit, ajouter ou retirer une étape, définir un livrable attendu. Réservé à l'UGP. */
  peutStructurer: boolean
  /** Renseigner : niveau atteint, valeur, observation, quantité réalisée. Ouvert au responsable de l'activité. */
  peutRenseigner: boolean
}

/** Suivi par étapes d'une activité (§ 6 du CR du 22/09/2026) — remplace le découpage libre en sous-activités. */
export function EtapesActivite({ activiteUuid, sousActiviteUuid, titre, peutStructurer, peutRenseigner }: EtapesActiviteProps) {
  const confirmer = useConfirmer()
  const { data: etapes } = useQuery(etapesActiviteQueryOptions(activiteUuid, sousActiviteUuid))
  const { data: gabarits } = useQuery({ ...gabaritsEtapesQueryOptions(), enabled: peutStructurer })

  const [gabaritChoisi, setGabaritChoisi] = useState('')
  const [etapeRenseignee, setEtapeRenseignee] = useState<EtapeActivite | null>(null)
  const [nouvelleEtape, setNouvelleEtape] = useState('')
  const [ajoutOuvert, setAjoutOuvert] = useState(false)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  const liste = etapes?.donnees ?? []

  async function executer(action: () => Promise<unknown>) {
    setErreur(null)
    setEnCours(true)
    try {
      await action()
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <section>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        {titre !== undefined ? <h2 className="text-lg font-semibold">{titre}</h2> : <span />}
        {peutStructurer && liste.length > 0 && !ajoutOuvert && (
          <Bouton variante="discret" taille="mini" icone={<IconePlus />} onClick={() => setAjoutOuvert(true)}>
            Ajouter une étape
          </Bouton>
        )}
      </div>

      {erreur && (
        <div role="alert" className="mb-3 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      {/* Pendant le chargement, rien ne dit encore que l'activité n'est pas structurée. */}
      {etapes === undefined && <p className="text-sm text-muted">Chargement des étapes…</p>}

      {etapes !== undefined && liste.length === 0 && (
        <div className="rounded-[var(--radius-carte)] bg-surface px-4 py-4 shadow-[var(--shadow-carte)]">
          <p className="text-muted">
            {sousActiviteUuid !== undefined ? 'Cette ligne' : 'Cette activité'} n'est pas encore structurée en étapes : sa
            progression reste indéterminée.
          </p>
          {peutStructurer && (
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <select
                value={gabaritChoisi}
                onChange={(e) => setGabaritChoisi(e.target.value)}
                disabled={enCours}
                aria-label="Gabarit à instancier"
                className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-sm"
              >
                <option value="">Choisir un gabarit…</option>
                {(gabarits?.donnees ?? []).map((g) => (
                  <option key={g.uuid} value={g.uuid}>
                    {g.libelle} ({g.etapes.length} étapes)
                  </option>
                ))}
              </select>
              <Bouton
                disabled={enCours || gabaritChoisi === ''}
                onClick={() => void executer(() => instancierGabarit(activiteUuid, gabaritChoisi, sousActiviteUuid))}
              >
                Instancier
              </Bouton>
            </div>
          )}
        </div>
      )}

      {peutStructurer && ajoutOuvert && (
        <div className="mb-3 flex flex-wrap items-center gap-2 rounded-[var(--radius-controle)] border border-border bg-nested p-3">
          <input
            value={nouvelleEtape}
            disabled={enCours}
            autoFocus
            onChange={(e) => setNouvelleEtape(e.target.value)}
            placeholder="Libellé de l'étape"
            className="min-w-48 flex-1 rounded-[var(--radius-controle)] border border-border px-3 py-1.5 text-sm"
          />
          <Bouton variante="secondaire" taille="mini" onClick={() => setAjoutOuvert(false)}>
            Annuler
          </Bouton>
          <Bouton
            taille="mini"
            disabled={enCours || nouvelleEtape.trim() === ''}
            onClick={() =>
              void executer(async () => {
                await ajouterEtape(activiteUuid, { libelle: nouvelleEtape.trim() }, sousActiviteUuid)
                setNouvelleEtape('')
                setAjoutOuvert(false)
              })
            }
          >
            Ajouter
          </Bouton>
        </div>
      )}

      {liste.length > 0 && (
        <ul className="flex flex-col gap-2">
          {liste.map((etape) => (
            <li key={etape.uuid} className="rounded-[var(--radius-carte)] bg-surface px-4 py-3 shadow-[var(--shadow-carte)]">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="flex min-w-0 items-center gap-2">
                  <span className="chiffres shrink-0 text-sm text-muted">{etape.ordre}</span>
                  <span className="truncate font-semibold">{etape.libelle}</span>
                </p>

                <div className="flex shrink-0 items-center gap-3">
                  <div className="flex items-center gap-2">
                    <div className="h-2 w-24 overflow-hidden rounded-[var(--radius-barre)] bg-track">
                      <div
                        className={etape.avancement === 100 ? 'h-full bg-success' : 'h-full bg-accent'}
                        style={{ width: `${etape.avancement}%` }}
                      />
                    </div>
                    <span className="chiffres text-sm">{etape.avancement} %</span>
                  </div>

                  {peutRenseigner && (
                    <IconeBouton libelle="Renseigner l'étape" onClick={() => setEtapeRenseignee(etape)}>
                      <IconeModifier />
                    </IconeBouton>
                  )}
                  {peutStructurer && (
                    <IconeBouton
                      libelle="Retirer l'étape"
                      disabled={enCours}
                      onClick={async () => {
                        if (await confirmer({ titre: 'Retirer l’étape', message: `L’étape « ${etape.libelle} » sera retirée, avec son avancement : la progression de l’activité sera recalculée sans elle.`, action: 'Retirer' })) {
                          void executer(() => supprimerEtape(etape.uuid))
                        }
                      }}
                      classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                    >
                      <IconeSupprimer />
                    </IconeBouton>
                  )}
                </div>
              </div>

              <p className="mt-1 flex flex-wrap items-center gap-x-3 text-sm text-muted">
                {etape.valeurActuelle !== null && (
                  <span className="chiffres">
                    {etape.valeurActuelle} {etape.unite ?? ''}
                  </span>
                )}
                <span>
                  {etape.dateMiseAJour ? `Mise à jour le ${formaterDate(etape.dateMiseAJour)}` : 'Jamais renseignée'}
                </span>
                {etape.echeance && <span>Échéance {formaterDate(etape.echeance)}</span>}
              </p>

              {etape.observation && <p className="mt-1 text-sm">{etape.observation}</p>}

              {etape.livrables.length > 0 && (
                <ul className="mt-2 ml-4 flex flex-col gap-1 border-l border-border pl-3 text-sm">
                  {etape.livrables.map((livrable) => (
                    <li key={livrable.uuid} className="flex flex-wrap items-center gap-2">
                      <span className="min-w-0 truncate">{livrable.libelle}</span>
                      <span className="chiffres text-muted">
                        {livrable.quantiteRealisee} / {livrable.quantiteAttendue} {livrable.unite ?? ''}
                      </span>
                      <span className="chiffres text-xs text-muted">{formaterTaux(livrable.tauxRealisation)}</span>
                      {peutRenseigner && (
                        <input
                          type="number"
                          min={0}
                          defaultValue={livrable.quantiteRealisee}
                          disabled={enCours}
                          aria-label={`Quantité réalisée — ${livrable.libelle}`}
                          onBlur={(e) => {
                            if (e.target.value !== livrable.quantiteRealisee) {
                              void executer(() => modifierLivrable(livrable.uuid, { quantiteRealisee: e.target.value }))
                            }
                          }}
                          className="chiffres w-24 rounded-[var(--radius-controle)] border border-border px-2 py-1 text-xs"
                        />
                      )}
                      {peutStructurer && (
                        <IconeBouton
                          libelle="Retirer le livrable"
                          disabled={enCours}
                          onClick={async () => {
                        if (await confirmer({ titre: 'Retirer le livrable', message: `Le livrable « ${livrable.libelle} » sera retiré de l’étape.`, action: 'Retirer' })) {
                          void executer(() => supprimerLivrable(livrable.uuid))
                        }
                      }}
                          classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                        >
                          <IconeAnnuler />
                        </IconeBouton>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              <div className="mt-2 flex flex-wrap items-center gap-3 text-sm">
                {etape.piecesJointes.map((piece) => (
                  <span key={piece.uuid} className="flex items-center gap-1.5">
                    <IconeTrombone />
                    <a href={urlTelechargerPieceJointe(piece.uuid)} className="truncate text-accent hover:text-accent-d">
                      {piece.nomFichier}
                    </a>
                    {piece.typeDocumentLibelle && <span className="shrink-0 rounded-full bg-nested px-2 py-0.5 text-xs text-muted">{piece.typeDocumentLibelle}</span>}
                  </span>
                ))}
                {peutRenseigner && (
                  <DeposerJustificatif etapeUuid={etape.uuid} enCours={enCours} onDeposer={executer} />
                )}
              </div>

              {peutStructurer && <AjoutLivrable etapeUuid={etape.uuid} enCours={enCours} onAjouter={executer} />}
            </li>
          ))}
        </ul>
      )}

      {etapeRenseignee && (
        <RenseignerEtapeModale
          etape={etapeRenseignee}
          activiteUuid={activiteUuid}
          onFermer={() => setEtapeRenseignee(null)}
        />
      )}
    </section>
  )
}

/** Dépôt d'une pièce justificative sur une étape (§ 6 du CR) : un simple champ fichier, le justificatif part dès sa sélection. */
function DeposerJustificatif({
  etapeUuid,
  enCours,
  onDeposer,
}: {
  etapeUuid: string
  enCours: boolean
  onDeposer: (action: () => Promise<unknown>) => Promise<void>
}) {
  const [ouvert, setOuvert] = useState(false)

  return (
    <>
      <Bouton variante="discret" taille="mini" icone={<IconeTrombone />} disabled={enCours} onClick={() => setOuvert(true)}>
        Joindre un justificatif
      </Bouton>
      {ouvert && (
        <JoindreFichierModale
          titre="Joindre un justificatif"
          tailleMaxOctets={10 * 1024 * 1024}
          onEnvoyer={(fichier, nature) => onDeposer(() => televerserPieceJointeEtape(etapeUuid, fichier, nature))}
          onFermer={() => setOuvert(false)}
        />
      )}
    </>
  )
}

function AjoutLivrable({
  etapeUuid,
  enCours,
  onAjouter,
}: {
  etapeUuid: string
  enCours: boolean
  onAjouter: (action: () => Promise<unknown>) => Promise<void>
}) {
  const [ouvert, setOuvert] = useState(false)
  const [libelle, setLibelle] = useState('')
  const [unite, setUnite] = useState('')
  const [quantite, setQuantite] = useState('')

  if (!ouvert) {
    return (
      <Bouton variante="discret" taille="mini" icone={<IconePlus />} onClick={() => setOuvert(true)} className="mt-2">
        Livrable attendu
      </Bouton>
    )
  }

  return (
    <div className="mt-2 flex flex-wrap items-center gap-2 rounded-[var(--radius-controle)] border border-border bg-nested p-2">
      <input
        value={libelle}
        autoFocus
        disabled={enCours}
        onChange={(e) => setLibelle(e.target.value)}
        placeholder="Libellé du livrable"
        className="min-w-40 flex-1 rounded-[var(--radius-controle)] border border-border px-2 py-1 text-sm"
      />
      <input
        value={quantite}
        inputMode="decimal"
        disabled={enCours}
        onChange={(e) => setQuantite(e.target.value)}
        placeholder="Quantité"
        className="chiffres w-24 rounded-[var(--radius-controle)] border border-border px-2 py-1 text-sm"
      />
      <input
        value={unite}
        disabled={enCours}
        onChange={(e) => setUnite(e.target.value)}
        placeholder="Unité"
        className="w-24 rounded-[var(--radius-controle)] border border-border px-2 py-1 text-sm"
      />
      <Bouton variante="secondaire" taille="mini" onClick={() => setOuvert(false)}>
        Annuler
      </Bouton>
      <Bouton
        taille="mini"
        disabled={enCours || libelle.trim() === '' || quantite.trim() === ''}
        onClick={() =>
          void onAjouter(async () => {
            await creerLivrable(etapeUuid, {
              libelle: libelle.trim(),
              unite: unite.trim() === '' ? undefined : unite.trim(),
              quantiteAttendue: quantite.trim(),
            })
            setLibelle('')
            setUnite('')
            setQuantite('')
            setOuvert(false)
          })
        }
      >
        Ajouter
      </Bouton>
    </div>
  )
}
