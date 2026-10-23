import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { projetCourantQueryOptions, arbreQueryOptions } from '@/lib/composantes'
import {
  documentsQueryOptions,
  FAMILLES,
  formaterTaille,
  LIBELLES_FAMILLE,
  LIBELLES_VISIBILITE,
  retirerDocument,
  urlTelechargerDocument,
  type Document,
  type TypeRattachement,
} from '@/lib/documents'
import { formaterDate } from '@/lib/format'
import { usePeut, usePeutRequalifierPiece } from '@/lib/use-permissions'
import { queryClient } from '@/lib/query-client'
import { Squelette } from '@/components/Squelette'
import { Bouton } from '@/components/Bouton'
import { IconeBouton, IconeModifier, IconePlus, IconeRecherche, IconeSupprimer, IconeTelecharger } from '@/components/Icones'
import { ModifierPieceJointeModale } from '@/components/ModifierPieceJointeModale'
import { DeposerDocumentModale } from '@/components/DeposerDocumentModale'
import { useToast } from '@/components/ToastProvider'
import { useConfirmer } from '@/lib/confirmation'
import { ComboboxSimple } from '@/components/ComboboxSimple'
import { TYPES_DOCUMENT } from '@/lib/types-document'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'

/**
 * Bibliothèque documentaire de l'UGP (§ 12 du CR du 22/09/2026).
 *
 * Deux niveaux de rattachement sur un même écran : les documents de projet,
 * qui valent pour tout le monde (manuels, canevas, comptes rendus), et ceux
 * d'une composante, qui ne concernent que son périmètre. La composante se
 * choisit dans l'URL, comme les autres filtres de l'application, pour qu'un
 * lien vers « les documents de la composante 2 » se partage.
 *
 * Consulter n'exige aucune permission : c'est la visibilité de chaque
 * document qui décide de ce que l'on voit, et le serveur filtre la liste
 * avec le même arbitre que le téléchargement. Déposer et retirer exigent
 * `documents.gerer` — l'UGP tient sa bibliothèque.
 */
export function Documents() {
  const confirmer = useConfirmer()
  const [searchParams, setSearchParams] = useSearchParams()
  const [modaleOuverte, setModaleOuverte] = useState(false)
  const [actionEnCours, setActionEnCours] = useState<string | null>(null)
  const peutGerer = usePeut('documents.gerer')
  const peutRequalifier = usePeutRequalifierPiece()
  const [enModification, setEnModification] = useState<Document | null>(null)
  const peutExporter = usePeut('exports.generer')
  const afficherToast = useToast()

  const { data: projet } = useQuery(projetCourantQueryOptions())
  const { data: arbre } = useQuery(arbreQueryOptions(projet?.uuid, 3))

  const composanteUuid = searchParams.get('composante')
  const type: TypeRattachement = composanteUuid === null ? 'projets' : 'composantes'
  const cibleUuid = composanteUuid ?? projet?.uuid

  // Les filtres vivent dans l'URL comme partout ailleurs (§ 8.1) : un lien
  // vers « les tableurs déposés en octobre » doit être partageable.
  const filtres = {
    q: searchParams.get('q') ?? '',
    du: searchParams.get('du') ?? '',
    au: searchParams.get('au') ?? '',
    famille: searchParams.get('famille') ?? '',
    type: searchParams.get('type') ?? '',
  }
  const filtresActifs = Object.values(filtres).some((valeur) => valeur !== '')

  const { data, isLoading, isError } = useQuery(documentsQueryOptions(type, cibleUuid, filtres))
  const composante = arbre?.composantes.find((c) => c.uuid === composanteUuid)

  function definirFiltre(cle: string, valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') {
      suivant.delete(cle)
    } else {
      suivant.set(cle, valeur)
    }
    setSearchParams(suivant, { replace: true })
  }

  function effacerFiltres() {
    const suivant = new URLSearchParams(searchParams)
    for (const cle of ['q', 'du', 'au', 'famille', 'type']) {
      suivant.delete(cle)
    }
    setSearchParams(suivant, { replace: true })
  }

  function choisirComposante(uuid: string) {
    const suivant = new URLSearchParams(searchParams)
    if (uuid === '') {
      suivant.delete('composante')
    } else {
      suivant.set('composante', uuid)
    }
    setSearchParams(suivant, { replace: true })
  }

  async function retirer(document: Document) {
    setActionEnCours(document.uuid)
    try {
      await retirerDocument(document.uuid)
      await queryClient.invalidateQueries({ queryKey: ['documents'] })
      afficherToast('Document retiré.')
    } catch {
      afficherToast('Le retrait a échoué. Réessayez.')
    } finally {
      setActionEnCours(null)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl">Documents</h1>
          <p className="text-muted">
            Bibliothèque de l'UGP — documents de cadrage du projet et pièces rattachées à une composante.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {peutExporter && (
            <BoutonExporter href={urlExportDonnees('documents', { ...filtres, composante: composanteUuid })} libelle="Exporter la liste" />
          )}
          {peutGerer && cibleUuid !== undefined && (
            <Bouton icone={<IconePlus />} onClick={() => setModaleOuverte(true)}>
              Déposer un document
            </Bouton>
          )}
        </div>
      </div>

      <div
        className="mb-6 flex flex-wrap items-center gap-3 rounded-[var(--radius-carte)] bg-surface p-3 shadow-[var(--shadow-carte)]"
        role="search"
        aria-label="Filtres de la bibliothèque"
      >
        <select
          aria-label="Rattachement"
          value={composanteUuid ?? ''}
          onChange={(e) => choisirComposante(e.target.value)}
          className="max-w-xs truncate rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2"
        >
          <option value="">Documents du projet</option>
          {(arbre?.composantes ?? []).map((c) => (
            <option key={c.uuid} value={c.uuid}>
              {c.code} — {c.libelle}
            </option>
          ))}
        </select>

        <div className="w-60">
          <ComboboxSimple
            options={[{ value: '', label: 'Tous les types' }, ...TYPES_DOCUMENT.map((t) => ({ value: t.valeur, label: t.libelle }))]}
            valeur={filtres.type}
            onChange={(valeur) => definirFiltre('type', valeur)}
            placeholder="Tous les types"
          />
        </div>

        <select
          aria-label="Format"
          value={filtres.famille}
          onChange={(e) => definirFiltre('famille', e.target.value)}
          className="rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2"
        >
          <option value="">Tous les formats</option>
          {FAMILLES.map((f) => (
            <option key={f.valeur} value={f.valeur}>
              {f.libelle}
            </option>
          ))}
        </select>

        <label className="flex items-center gap-2 text-sm text-muted">
          Déposé du
          <input
            type="date"
            value={filtres.du}
            onChange={(e) => definirFiltre('du', e.target.value)}
            className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-text"
          />
        </label>
        <label className="flex items-center gap-2 text-sm text-muted">
          au
          <input
            type="date"
            value={filtres.au}
            onChange={(e) => definirFiltre('au', e.target.value)}
            className="chiffres rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-text"
          />
        </label>

        <div className="relative min-w-56 flex-1">
          <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted">
            <IconeRecherche />
          </span>
          <input
            type="search"
            aria-label="Rechercher un document"
            placeholder="Rechercher un intitulé, un nom de fichier…"
            value={filtres.q}
            onChange={(e) => definirFiltre('q', e.target.value)}
            className="w-full rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
          />
        </div>

        {filtresActifs && (
          <Bouton variante="discret" onClick={effacerFiltres}>
            Effacer les filtres
          </Bouton>
        )}
      </div>

      <section className="rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
        <div className="border-b border-border px-4 py-3">
          <h2 className="text-base font-semibold">
            {composante ? `${composante.code} — ${composante.libelle}` : 'Documents du projet'}
          </h2>
        </div>

        {isLoading && (
          <div className="p-4">
            <Squelette lignes={3} />
          </div>
        )}
        {isError && <p className="p-4 text-bad">Impossible de charger les documents. Réessayez.</p>}

        {data && data.donnees.length === 0 && (
          <p className="px-4 py-10 text-center text-sm text-muted">
            {filtresActifs
              ? 'Aucun document ne correspond à ces filtres.'
              : "Aucun document à ce niveau pour l'instant." +
                (peutGerer ? ' Déposez-en un pour démarrer la bibliothèque.' : '')}
          </p>
        )}

        {data && data.donnees.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[48rem] border-collapse text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted">
                  <th className="px-4 py-3 font-semibold">Document</th>
                  <th className="px-4 py-3 font-semibold">Type</th>
                  <th className="px-4 py-3 font-semibold">Format</th>
                  <th className="px-4 py-3 font-semibold">Visibilité</th>
                  <th className="px-4 py-3 font-semibold">Déposé par</th>
                  <th className="px-4 py-3 font-semibold">Le</th>
                  <th className="px-4 py-3 text-right font-semibold">Taille</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody>
                {data.donnees.map((document) => (
                  <tr key={document.uuid} className="border-b border-border last:border-0 hover:bg-hover">
                    <td className="px-4 py-3">
                      <a
                        href={urlTelechargerDocument(document.uuid)}
                        className="font-semibold text-accent hover:underline"
                      >
                        {document.libelle ?? document.nomFichier}
                      </a>
                      {document.libelle !== null && <p className="text-xs text-muted">{document.nomFichier}</p>}
                    </td>
                    <td className="px-4 py-3">{document.typeDocumentLibelle ?? <span className="text-muted">Non précisé</span>}</td>
                    <td className="px-4 py-3 text-muted">{LIBELLES_FAMILLE[document.famille] ?? document.famille}</td>
                    <td className="px-4 py-3 text-muted">
                      {LIBELLES_VISIBILITE[document.visibilite] ?? document.visibilite}
                    </td>
                    <td className="px-4 py-3">{document.deposePar}</td>
                    <td className="chiffres px-4 py-3 text-muted">{formaterDate(document.creeLe)}</td>
                    <td className="chiffres px-4 py-3 text-right text-muted">{formaterTaille(document.tailleOctets)}</td>
                    <td className="px-4 py-3">
                      <div className="flex items-center justify-end gap-1">
                        <a
                          href={urlTelechargerDocument(document.uuid)}
                          title="Télécharger"
                          aria-label={`Télécharger ${document.libelle ?? document.nomFichier}`}
                          className="rounded-[var(--radius-controle)] p-1.5 text-muted transition-colors hover:bg-accent-bg hover:text-accent"
                        >
                          <IconeTelecharger />
                        </a>
                        {peutRequalifier(document.deposeParUuid) && (
                          <IconeBouton libelle="Modifier l'intitulé ou le type" onClick={() => setEnModification(document)}>
                            <IconeModifier />
                          </IconeBouton>
                        )}
                        {peutGerer && (
                          <IconeBouton
                            libelle="Retirer le document"
                            disabled={actionEnCours === document.uuid}
                            onClick={async () => {
                        if (await confirmer({ titre: 'Retirer le document', message: `« ${document.libelle ?? document.nomFichier} » sera retiré de la bibliothèque.`, action: 'Retirer' })) {
                          void retirer(document)
                        }
                      }}
                            classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                          >
                            <IconeSupprimer />
                          </IconeBouton>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {enModification !== null && (
        <ModifierPieceJointeModale piece={enModification} invalider={[['documents']]} onFermer={() => setEnModification(null)} />
      )}

      {modaleOuverte && cibleUuid !== undefined && (
        <DeposerDocumentModale
          type={type}
          cibleUuid={cibleUuid}
          cibleLibelle={composante ? `${composante.code} — ${composante.libelle}` : 'Documents du projet'}
          onFermer={() => setModaleOuverte(false)}
        />
      )}
    </div>
  )
}
