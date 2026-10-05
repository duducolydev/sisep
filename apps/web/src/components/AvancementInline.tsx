import { useState, type KeyboardEvent } from 'react'
import * as Popover from '@radix-ui/react-popover'
import { saisirRealisation } from '@/lib/composantes'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'
import { usePeutSur } from '@/lib/use-permissions'
import { IconeModifier, IconePlus } from './Icones'

interface AvancementInlineProps {
  avancement: number
  sousActiviteUuid: string
  activiteUuid: string
  /** Structure responsable effective (sous-activité, sinon activité parente — même repli que SousActivite::getStructurePourPortee côté serveur) ; `null` si aucune. */
  structureResponsableUuid: string | null
}

/**
 * Édition inline de l'avancement physique d'une sous-activité (§ 2.2, § 6.2),
 * en mini-formulaire flottant (Popover Radix) — même mécanique que
 * ValeurIndicateurInline, pour ne pas déformer la ligne de la sous-activité
 * pendant la saisie.
 */
export function AvancementInline({ avancement, sousActiviteUuid, activiteUuid, structureResponsableUuid }: AvancementInlineProps) {
  const peutModifier = usePeutSur('activites.modifier', structureResponsableUuid)

  const [ouvert, setOuvert] = useState(false)
  const [valeur, setValeur] = useState(avancement)
  const [commentaire, setCommentaire] = useState('')
  const [commentaireOuvert, setCommentaireOuvert] = useState(false)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  function surChangementOuverture(suivant: boolean) {
    if (suivant) {
      setValeur(avancement)
      setCommentaire('')
      setCommentaireOuvert(false)
      setErreur(null)
    }
    setOuvert(suivant)
  }

  function gererToucheRaccourci(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      void enregistrer()
    }
  }

  async function enregistrer() {
    setErreur(null)
    setEnCours(true)
    try {
      await saisirRealisation(sousActiviteUuid, {
        avancement: valeur,
        dateConstat: new Date().toISOString().slice(0, 10),
        commentaire: commentaire.trim() === '' ? undefined : commentaire.trim(),
      })
      await queryClient.invalidateQueries({ queryKey: ['activite', activiteUuid] })
      await queryClient.invalidateQueries({ queryKey: ['arbre'] })
      setOuvert(false)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div className="flex min-w-0 items-center gap-2">
      <div className="h-1.5 w-16 shrink-0 overflow-hidden rounded-[var(--radius-barre)] bg-track">
        <div className="h-full bg-ok" style={{ width: `${avancement}%` }} />
      </div>
      <span className="chiffres shrink-0 text-xs">{avancement}%</span>

      {peutModifier && (
        <Popover.Root open={ouvert} onOpenChange={surChangementOuverture}>
          <Popover.Trigger asChild>
            <button
              type="button"
              title="Modifier l'avancement"
              aria-label="Modifier l'avancement"
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
              <div className="flex items-center gap-2">
                <input
                  type="number"
                  min={0}
                  max={100}
                  value={valeur}
                  disabled={enCours}
                  onChange={(e) => setValeur(Number(e.target.value))}
                  onKeyDown={gererToucheRaccourci}
                  autoFocus
                  className="chiffres w-full rounded-[var(--radius-controle)] border border-accent bg-surface px-2 py-1.5 text-right"
                />
                <span className="shrink-0 text-xs text-muted">%</span>
              </div>

              {commentaireOuvert ? (
                <input
                  type="text"
                  placeholder="Observation (optionnel)"
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
                  Ajouter une observation
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
    </div>
  )
}
