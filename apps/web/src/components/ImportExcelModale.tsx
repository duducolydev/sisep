import { useState } from 'react'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { DropzoneFichier } from './DropzoneFichier'
import { IconeTelecharger } from './Icones'
import {
  envoyerImport,
  EXTENSIONS_IMPORT,
  TAILLE_MAX_IMPORT_OCTETS,
  urlModeleImport,
  type RapportImport,
  type StatutLigneImport,
  type TypeImport,
} from '@/lib/imports'

const STATUTS: Record<StatutLigneImport, { libelle: string; classe: string }> = {
  creation: { libelle: 'À créer', classe: 'bg-success/15 text-success' },
  mise_a_jour: { libelle: 'À mettre à jour', classe: 'bg-accent-bg text-accent' },
  existante: { libelle: 'Déjà présente', classe: 'bg-nested text-muted' },
  doublon: { libelle: 'Doublon', classe: 'bg-nested text-muted' },
  erreur: { libelle: 'Erreur', classe: 'bg-bad-bg text-bad' },
}

interface ImportExcelModaleProps {
  type: TypeImport
  titre: string
  onFermer: () => void
  /** Après un import effectif : rafraîchir les listes concernées. */
  onImporte: (rapport: RapportImport) => Promise<void> | void
}

/**
 * Import par fichier Excel en deux temps : un aperçu ligne à ligne
 * (créations, mises à jour, doublons, erreurs) sans rien écrire, puis
 * l'import des seules lignes valides.
 */
export function ImportExcelModale({ type, titre, onFermer, onImporte }: ImportExcelModaleProps) {
  const [fichier, setFichier] = useState<File | null>(null)
  const [miseAJour, setMiseAJour] = useState(false)
  const [apercu, setApercu] = useState<RapportImport | null>(null)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  function choisirFichier(suivant: File | null) {
    setFichier(suivant)
    setApercu(null)
    setErreur(null)
  }

  async function analyser(confirmer: boolean) {
    if (fichier === null) {
      setErreur('Choisissez un fichier.')

      return
    }
    const extension = fichier.name.slice(fichier.name.lastIndexOf('.')).toLowerCase()
    if (!EXTENSIONS_IMPORT.includes(extension)) {
      setErreur('Format non pris en charge : .xlsx, .xls ou .csv.')

      return
    }
    if (fichier.size > TAILLE_MAX_IMPORT_OCTETS) {
      setErreur('Fichier trop volumineux : 5 Mo au plus.')

      return
    }

    setErreur(null)
    setEnCours(true)
    try {
      const rapport = await envoyerImport(type, fichier, { miseAJour, confirmer })
      if (confirmer) {
        await onImporte(rapport)
        onFermer()
      } else {
        setApercu(rapport)
      }
    } catch (e) {
      setErreur(e instanceof Error ? e.message : "L'import a échoué. Réessayez.")
    } finally {
      setEnCours(false)
    }
  }

  const aImporter = apercu ? apercu.resume.creations + apercu.resume.misesAJour : 0

  return (
    <Modale titre={titre} onFermer={onFermer} taille="large">
      {erreur !== null && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-controle)] bg-nested px-4 py-3 text-sm">
          <p className="text-muted">Partez du modèle : colonnes attendues, listes de valeurs et instructions.</p>
          <a
            href={urlModeleImport(type)}
            className="flex items-center gap-1.5 rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-1.5 font-semibold text-accent transition-colors hover:border-accent"
          >
            <IconeTelecharger />
            Télécharger le modèle Excel
          </a>
        </div>

        <div className="flex flex-col gap-1">
          <DropzoneFichier fichier={fichier} onChange={choisirFichier} accept={EXTENSIONS_IMPORT.join(',')} />
          <p className="text-xs text-muted">.xlsx, .xls ou .csv — 5 Mo et 2 000 lignes au plus.</p>
        </div>

        <label className="flex items-start gap-2 text-sm">
          <input
            type="checkbox"
            checked={miseAJour}
            onChange={(e) => {
              setMiseAJour(e.target.checked)
              setApercu(null)
            }}
            className="mt-0.5"
          />
          <span>
            Mettre à jour les enregistrements déjà présents
            <span className="block text-xs text-muted">Sinon ils sont ignorés. Une cellule vide ne remplace jamais une valeur connue.</span>
          </span>
        </label>

        {apercu && (
          <section className="flex flex-col gap-3">
            <ul className="flex flex-wrap gap-2 text-xs" aria-label="Résumé de l'aperçu">
              <Compteur libelle="à créer" valeur={apercu.resume.creations} classe={STATUTS.creation.classe} />
              <Compteur libelle="à mettre à jour" valeur={apercu.resume.misesAJour} classe={STATUTS.mise_a_jour.classe} />
              <Compteur libelle={apercu.resume.existantes > 1 ? 'déjà présentes' : 'déjà présente'} valeur={apercu.resume.existantes} classe={STATUTS.existante.classe} />
              <Compteur libelle={apercu.resume.doublons > 1 ? 'doublons' : 'doublon'} valeur={apercu.resume.doublons} classe={STATUTS.doublon.classe} />
              <Compteur libelle={apercu.resume.erreurs > 1 ? 'erreurs' : 'erreur'} valeur={apercu.resume.erreurs} classe={STATUTS.erreur.classe} />
            </ul>
            <p className="text-xs text-muted">
              {apercu.fichier.format.toUpperCase()}
              {apercu.fichier.encodage && ` · encodage ${apercu.fichier.encodage}`}
              {apercu.fichier.colonnesIgnorees.length > 0 && ` · colonnes ignorées : ${apercu.fichier.colonnesIgnorees.join(', ')}`}
            </p>
            <div className="max-h-72 overflow-auto rounded-[var(--radius-controle)] border border-border">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-surface">
                  <tr className="border-b border-border text-left text-muted">
                    <th className="px-3 py-2 text-right font-normal">Ligne</th>
                    <th className="px-3 py-2 font-normal">Enregistrement</th>
                    <th className="px-3 py-2 font-normal">Statut</th>
                    <th className="px-3 py-2 font-normal">Détail</th>
                  </tr>
                </thead>
                <tbody>
                  {apercu.lignes.map((l) => (
                    <tr key={l.ligne} className="border-b border-border align-top last:border-0">
                      <td className="chiffres px-3 py-2 text-right text-muted">{l.ligne}</td>
                      <td className="px-3 py-2">{l.libelle}</td>
                      <td className="px-3 py-2">
                        <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ${STATUTS[l.statut].classe}`}>
                          {STATUTS[l.statut].libelle}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-xs text-muted">
                        {l.messages.length > 0 && (
                          <ul className="flex flex-col gap-0.5">
                            {l.messages.map((m) => (
                              <li key={m}>{m}</li>
                            ))}
                          </ul>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </div>

      <div className="mt-5 flex flex-wrap justify-end gap-3 border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Annuler
        </Bouton>
        {apercu === null ? (
          <Bouton disabled={enCours || fichier === null} onClick={() => void analyser(false)}>
            {enCours ? 'Analyse…' : 'Analyser le fichier'}
          </Bouton>
        ) : (
          <Bouton disabled={enCours || aImporter === 0} onClick={() => void analyser(true)}>
            {enCours ? 'Import…' : aImporter === 0 ? 'Aucune ligne à importer' : `Importer ${aImporter} ligne${aImporter > 1 ? 's' : ''}`}
          </Bouton>
        )}
      </div>
    </Modale>
  )
}

function Compteur({ libelle, valeur, classe }: { libelle: string; valeur: number; classe: string }) {
  return (
    <li className={`rounded-full px-2.5 py-1 font-semibold ${classe}`}>
      <span className="chiffres">{valeur}</span> {libelle}
    </li>
  )
}
