import { useRef, useState, type ReactNode } from 'react'
import { toCanvas } from 'html-to-image'
import { usePeut } from '@/lib/use-permissions'
import { IconeTelecharger } from './Icones'

interface GraphiqueExportableProps {
  /** Titre du graphique : en tête de l'image et dans le nom du fichier. */
  titre: string
  /** Précision sous le titre de l'image (exercice, périmètre…). */
  sousTitre?: string
  /**
   * Le titre est déjà affiché dans le cadre capturé (titre de carte) : il
   * n'est pas répété en tête de l'image.
   */
  titreDansLeCadre?: boolean
  /** Classes du cadre capturé — celles de la carte qui porte le graphique. */
  className?: string
  children: ReactNode
}

/** Couleurs de la charte (tokens.css), pour l'en-tête et le pied dessinés sur l'image. */
const FOND = '#FFFDF9'
const ENCRE = '#0E1F2E'
const DISCRET = '#6E6A60'

/**
 * Tout graphique de la plateforme, exportable en image PNG pour les
 * rapports — réservé à l'équipe qui les rédige (graphiques.exporter :
 * administrateur, coordonnateur, responsable suivi-évaluation). Capture le
 * cadre tel qu'il est affiché, filtres compris, avec un titre et une
 * mention de source ; le bouton lui-même n'apparaît pas sur l'image.
 */
export function GraphiqueExportable({ titre, sousTitre, titreDansLeCadre = false, className = '', children }: GraphiqueExportableProps) {
  const peutExporter = usePeut('graphiques.exporter')
  const cadre = useRef<HTMLDivElement>(null)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState(false)

  async function exporter() {
    if (!cadre.current) return
    setEnCours(true)
    setErreur(false)
    try {
      await document.fonts.ready
      const graphique = await toCanvas(cadre.current, {
        pixelRatio: 2,
        backgroundColor: FOND,
        // L'ombre et l'arrondi de la carte n'ont pas leur place sur l'image.
        style: { boxShadow: 'none', borderRadius: '0', margin: '0' },
        filter: (noeud) => !(noeud instanceof HTMLElement && noeud.dataset.exportIgnore === 'oui'),
      })
      telecharger(composer(graphique, titreDansLeCadre ? null : titre, sousTitre), titre)
    } catch {
      setErreur(true)
    } finally {
      setEnCours(false)
    }
  }

  return (
    <div ref={cadre} className={`relative ${className}`}>
      {peutExporter && (
        // Titre de carte : le bouton se loge à sa droite. Sinon, une ligne à
        // part, pour ne jamais masquer le haut du graphique — exclue de l'image.
        <div
          data-export-ignore="oui"
          className={titreDansLeCadre ? 'absolute right-3 top-3 z-10 flex items-center gap-2' : 'mb-2 flex items-center justify-end gap-2'}
        >
          {erreur && <span className="rounded bg-bad-bg px-2 py-0.5 text-xs text-bad">Export impossible</span>}
          <button
            type="button"
            onClick={() => void exporter()}
            disabled={enCours}
            title={`Exporter « ${titre} » en image (PNG)`}
            aria-label={`Exporter « ${titre} » en image (PNG)`}
            className="flex items-center gap-1 rounded-[var(--radius-controle)] border border-border bg-surface/90 px-2 py-1 text-xs font-semibold text-muted transition-colors hover:border-accent hover:text-accent disabled:opacity-60"
          >
            <IconeTelecharger />
            {enCours ? '…' : 'Image'}
          </button>
        </div>
      )}
      {children}
    </div>
  )
}

/** L'image finale : titre (si besoin), graphique, mention de source et date. */
function composer(graphique: HTMLCanvasElement, titre: string | null, sousTitre: string | undefined): HTMLCanvasElement {
  const echelle = 2
  const marge = 24 * echelle
  const hauteurTitre = titre ? (sousTitre ? 56 : 36) * echelle : 0
  const hauteurPied = 28 * echelle

  const image = document.createElement('canvas')
  image.width = graphique.width + 2 * marge
  image.height = graphique.height + hauteurTitre + hauteurPied + 2 * marge
  const ctx = image.getContext('2d')
  if (!ctx) return graphique

  ctx.fillStyle = FOND
  ctx.fillRect(0, 0, image.width, image.height)
  ctx.textBaseline = 'top'
  if (titre) {
    ctx.fillStyle = ENCRE
    ctx.font = `700 ${18 * echelle}px "Bricolage Grotesque", sans-serif`
    ctx.fillText(titre, marge, marge)
    if (sousTitre) {
      ctx.fillStyle = DISCRET
      ctx.font = `${13 * echelle}px "Public Sans", sans-serif`
      ctx.fillText(sousTitre, marge, marge + 26 * echelle)
    }
  }
  ctx.drawImage(graphique, marge, marge + hauteurTitre)

  const date = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeZone: 'Africa/Dakar' }).format(new Date())
  ctx.fillStyle = DISCRET
  ctx.font = `${11 * echelle}px "Public Sans", sans-serif`
  ctx.fillText(`Source : SISEP — Projet PHASAOC, ANSD · extrait le ${date}`, marge, image.height - marge - 14 * echelle)

  return image
}

function telecharger(image: HTMLCanvasElement, titre: string): void {
  const nom = titre
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
  const jour = new Date().toISOString().slice(0, 10)
  image.toBlob((blob) => {
    if (!blob) return
    const lien = document.createElement('a')
    lien.href = URL.createObjectURL(blob)
    lien.download = `${nom || 'graphique'}-${jour}.png`
    lien.click()
    setTimeout(() => URL.revokeObjectURL(lien.href), 1000)
  }, 'image/png')
}
