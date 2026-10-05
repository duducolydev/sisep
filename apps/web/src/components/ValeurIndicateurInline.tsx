import { useState, type KeyboardEvent } from 'react'
import * as Popover from '@radix-ui/react-popover'
import { saisirValeur, televerserPieceJointeValeur } from '@/lib/indicateurs'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { formaterValeurIndicateur } from '@/lib/format'
import { IconeModifier, IconePlus, IconeCalendrier, IconeTrombone } from './Icones'
import { DropzoneFichier } from './DropzoneFichier'

interface ValeurIndicateurInlineProps {
  indicateurUuid: string
  actuel: string | null
  unite: string
  /** Hint serveur (§ 8) : indicateurs.saisir (portée-aware) OU une invitation active sur cet indicateur précis — usePeutSur() seul ne détecterait pas l'accès par invitation. */
  peutSaisir: boolean
}

type ModeSaisie = 'ajustement' | 'absolu'

const AUJOURDHUI = new Date().toISOString().slice(0, 10)

/**
 * Édition inline de la valeur périodique d'un indicateur (§ 2.4, § 6.2), en
 * mini-formulaire flottant (Popover Radix — portalé hors du tableau, sinon
 * `overflow-x-auto` sur le conteneur du tableau le tronquerait). La période
 * reste ajustable mais repliée par défaut : `indicateur_valeurs` a une
 * contrainte d'unicité (indicateur, période), donc corriger la date doit
 * rester possible après un refus, sans encombrer le cas courant (valeur
 * seule).
 *
 * Mode « Ajustement » par défaut (§ décision) : la quantité saisie est un
 * delta +/- depuis la dernière saisie (validée ou non), pas la valeur
 * absolue — évite de tout ressaisir à chaque mise à jour périodique.
 */
export function ValeurIndicateurInline({ indicateurUuid, actuel, unite, peutSaisir }: ValeurIndicateurInlineProps) {
  const [ouvert, setOuvert] = useState(false)
  const [mode, setMode] = useState<ModeSaisie>('ajustement')
  const [valeur, setValeur] = useState('')
  const [periode, setPeriode] = useState(AUJOURDHUI)
  const [periodeOuverte, setPeriodeOuverte] = useState(false)
  const [commentaire, setCommentaire] = useState('')
  const [commentaireOuvert, setCommentaireOuvert] = useState(false)
  const [fichier, setFichier] = useState<File | null>(null)
  const [fichierOuvert, setFichierOuvert] = useState(false)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  function surChangementOuverture(suivant: boolean) {
    if (suivant) {
      setMode('ajustement')
      setValeur('')
      setPeriode(AUJOURDHUI)
      setPeriodeOuverte(false)
      setCommentaire('')
      setCommentaireOuvert(false)
      setFichier(null)
      setFichierOuvert(false)
      setErreur(null)
    }
    setOuvert(suivant)
  }

  function changerMode(suivant: ModeSaisie) {
    setMode(suivant)
    setValeur(suivant === 'absolu' && actuel !== null ? Number(actuel).toFixed(1) : '')
  }

  function gererToucheRaccourci(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      void enregistrer()
    }
  }

  async function enregistrer() {
    if (valeur.trim() === '') {
      setErreur(mode === 'ajustement' ? "L'ajustement est requis." : 'La valeur est requise.')

      return
    }

    setErreur(null)
    setEnCours(true)
    try {
      const reponse = await saisirValeur(indicateurUuid, {
        periode,
        ...(mode === 'ajustement' ? { delta: valeur.trim() } : { valeur: valeur.trim() }),
        commentaire: commentaire.trim() === '' ? undefined : commentaire.trim(),
        desagregations: [],
      })
      if (fichier) {
        await televerserPieceJointeValeur(reponse.uuid, fichier)
      }
      await queryClient.invalidateQueries({ queryKey: ['indicateur', indicateurUuid] })
      await queryClient.invalidateQueries({ queryKey: ['indicateurs'] })
      setOuvert(false)
    } catch (e) {
      setErreur(e instanceof ApiError || e instanceof Error ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <span className="inline-flex items-center justify-end gap-2">
      <span className="chiffres">{actuel === null ? '—' : `${formaterValeurIndicateur(actuel)} ${unite}`}</span>

      {peutSaisir && (
        <Popover.Root open={ouvert} onOpenChange={surChangementOuverture}>
          <Popover.Trigger asChild>
            <button
              type="button"
              title="Saisir une valeur"
              aria-label="Saisir une valeur"
              className="inline-flex shrink-0 items-center justify-center rounded-[var(--radius-controle)] p-1.5 text-muted transition-colors hover:bg-accent-bg hover:text-accent"
            >
              <IconeModifier />
            </button>
          </Popover.Trigger>
          <Popover.Portal>
            <Popover.Content
              align="end"
              sideOffset={6}
              className="z-20 w-72 rounded-[var(--radius-carte)] border border-border bg-surface p-3 text-left text-sm font-normal shadow-[var(--shadow-carte)]"
            >
              <div className="mb-2 flex gap-0.5 rounded-[var(--radius-controle)] bg-nested p-0.5 text-xs">
                <button
                  type="button"
                  disabled={enCours}
                  onClick={() => changerMode('ajustement')}
                  className={`flex-1 rounded-[calc(var(--radius-controle)-2px)] py-1 font-semibold transition-colors ${
                    mode === 'ajustement' ? 'bg-accent text-white' : 'text-muted hover:text-accent'
                  }`}
                >
                  Ajustement
                </button>
                <button
                  type="button"
                  disabled={enCours}
                  onClick={() => changerMode('absolu')}
                  className={`flex-1 rounded-[calc(var(--radius-controle)-2px)] py-1 font-semibold transition-colors ${
                    mode === 'absolu' ? 'bg-accent text-white' : 'text-muted hover:text-accent'
                  }`}
                >
                  Valeur absolue
                </button>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="text"
                  inputMode="decimal"
                  placeholder={mode === 'ajustement' ? 'ex. +5 ou -2.5' : undefined}
                  value={valeur}
                  disabled={enCours}
                  onChange={(e) => setValeur(e.target.value)}
                  onKeyDown={gererToucheRaccourci}
                  autoFocus
                  className="chiffres w-full rounded-[var(--radius-controle)] border border-accent bg-surface px-2 py-1.5 text-right"
                />
                <span className="shrink-0 text-xs text-muted">{unite}</span>
              </div>
              {mode === 'ajustement' && (
                <p className="mt-1 text-xs text-muted">Quantité en plus (+) ou en moins (-) depuis la dernière saisie.</p>
              )}

              <div className="mt-2 flex items-center gap-1.5">
                {periodeOuverte ? (
                  <>
                    <IconeCalendrier />
                    <label htmlFor={`periode-${indicateurUuid}`} className="text-xs text-muted">
                      Période
                    </label>
                    <input
                      id={`periode-${indicateurUuid}`}
                      type="date"
                      value={periode}
                      disabled={enCours}
                      onChange={(e) => setPeriode(e.target.value)}
                      onKeyDown={gererToucheRaccourci}
                      className="chiffres ml-auto rounded-[var(--radius-controle)] border border-border bg-surface px-2 py-1 text-xs"
                    />
                  </>
                ) : (
                  <button
                    type="button"
                    onClick={() => setPeriodeOuverte(true)}
                    className="flex items-center gap-1 text-xs text-muted hover:text-accent"
                  >
                    <IconeCalendrier />
                    Période : aujourd'hui — modifier
                  </button>
                )}
              </div>

              {commentaireOuvert ? (
                <input
                  type="text"
                  placeholder="Commentaire (optionnel)"
                  value={commentaire}
                  disabled={enCours}
                  onChange={(e) => setCommentaire(e.target.value)}
                  onKeyDown={gererToucheRaccourci}
                  autoFocus
                  className="mt-2 w-full rounded-[var(--radius-controle)] border border-border bg-surface px-2 py-1.5 text-xs"
                />
              ) : (
                <button
                  type="button"
                  onClick={() => setCommentaireOuvert(true)}
                  className="mt-2 flex items-center gap-1 rounded-[var(--radius-controle)] bg-accent px-2 py-1 text-xs font-semibold text-white transition-colors hover:bg-accent-d"
                >
                  <IconePlus />
                  Ajouter un commentaire
                </button>
              )}

              {fichierOuvert ? (
                <div className="mt-2">
                  <DropzoneFichier fichier={fichier} onChange={setFichier} />
                </div>
              ) : (
                <button
                  type="button"
                  onClick={() => setFichierOuvert(true)}
                  className="mt-2 flex items-center gap-1 rounded-[var(--radius-controle)] bg-accent px-2 py-1 text-xs font-semibold text-white transition-colors hover:bg-accent-d"
                >
                  <IconeTrombone />
                  Joindre un fichier justificatif
                </button>
              )}

              {erreur && (
                <p role="alert" className="mt-2 text-xs text-bad">
                  {erreur}
                </p>
              )}

              <div className="mt-3 flex justify-end gap-2">
                <Popover.Close asChild>
                  {/* Élément natif et non <Bouton> : Popover.Close asChild clone l'enfant et lui transmet sa ref. */}
                  <button
                    type="button"
                    disabled={enCours}
                    className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-1.5 text-sm font-semibold text-text transition-colors hover:border-accent hover:text-accent disabled:opacity-60"
                  >
                    Annuler
                  </button>
                </Popover.Close>
                <button
                  type="button"
                  onClick={enregistrer}
                  disabled={enCours}
                  className="rounded-[var(--radius-controle)] bg-accent px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-accent-d disabled:opacity-60"
                >
                  {enCours ? 'Enregistrement…' : 'Enregistrer'}
                </button>
              </div>
            </Popover.Content>
          </Popover.Portal>
        </Popover.Root>
      )}
    </span>
  )
}
