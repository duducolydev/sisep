import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { Bouton } from '@/components/Bouton'
import { ContratsPersonnelModale } from '@/components/ContratsPersonnelModale'
import { EffectifEnqueteursModale } from '@/components/EffectifEnqueteursModale'
import { PersonnelModale } from '@/components/PersonnelModale'
import { TableauBordRh } from '@/components/TableauBordRh'
import { Squelette } from '@/components/Squelette'
import { useToast } from '@/components/ToastProvider'
import { IconeBouton, IconeCalendrier, IconeImporter, IconeModifier, IconePlus, IconeRecherche, IconeSupprimer } from '@/components/Icones'
import { ApiError } from '@/lib/api-client'
import { formaterDate } from '@/lib/format'
import { queryClient } from '@/lib/query-client'
import {
  LIBELLES_SEXE,
  echeancesContratsQueryOptions,
  effectifsEnqueteursQueryOptions,
  personnelsQueryOptions,
  supprimerEffectifEnqueteurs,
  supprimerPersonnel,
  type EffectifEnqueteurs,
  type Personnel,
} from '@/lib/rh'
import { usePeut } from '@/lib/use-permissions'
import { useConfirmer } from '@/lib/confirmation'
import { ImportExcelModale } from '@/components/ImportExcelModale'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'

type Vue = 'personnel' | 'enqueteurs' | 'tableau-de-bord'

const VUES: { valeur: Vue; libelle: string }[] = [
  { valeur: 'personnel', libelle: 'Personnel technique' },
  { valeur: 'enqueteurs', libelle: 'Enquêteurs' },
  { valeur: 'tableau-de-bord', libelle: 'Tableau de bord' },
]

/** Suivi des ressources humaines du projet (§ 10 et § 11 du CR du 22/09/2026) — aucune donnée salariale n'y figure, elles sont écartées du système. */
export function RessourcesHumaines() {
  const confirmer = useConfirmer()
  const [searchParams, setSearchParams] = useSearchParams()
  const vue = (searchParams.get('vue') as Vue | null) ?? 'personnel'
  const recherche = searchParams.get('q') ?? ''

  const { data: personnels, isLoading, isError } = useQuery(personnelsQueryOptions())
  const { data: effectifs } = useQuery(effectifsEnqueteursQueryOptions())
  const { data: echeances } = useQuery(echeancesContratsQueryOptions(90))
  const peutGerer = usePeut('rh.gerer')
  const afficherToast = useToast()
  const [importOuvert, setImportOuvert] = useState(false)

  const [modalePersonnel, setModalePersonnel] = useState<{ personnel?: Personnel } | null>(null)
  const [modaleContrats, setModaleContrats] = useState<Personnel | null>(null)
  const [modaleEffectif, setModaleEffectif] = useState<{ effectif?: EffectifEnqueteurs } | null>(null)
  const [actionEnCours, setActionEnCours] = useState<string | null>(null)
  const [erreur, setErreur] = useState<string | null>(null)

  function definirFiltre(cle: 'vue' | 'q', valeur: string) {
    const suivant = new URLSearchParams(searchParams)
    if (valeur === '') {
      suivant.delete(cle)
    } else {
      suivant.set(cle, valeur)
    }
    setSearchParams(suivant, { replace: true })
  }

  async function executer(uuid: string, action: () => Promise<unknown>, message: string) {
    setErreur(null)
    setActionEnCours(uuid)
    try {
      await action()
      await queryClient.invalidateQueries({ queryKey: ['rh'] })
      afficherToast(message)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setActionEnCours(null)
    }
  }

  const terme = recherche.trim().toLowerCase()
  const personnelsFiltres = (personnels?.donnees ?? []).filter(
    (p) =>
      terme === ''
      || p.nomComplet.toLowerCase().includes(terme)
      || p.fonction.toLowerCase().includes(terme)
      || (p.profil ?? '').toLowerCase().includes(terme)
      || (p.activite?.libelle ?? '').toLowerCase().includes(terme),
  )

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-titre text-eyebrow uppercase tracking-wide">Équipe du projet</p>
          <h1 className="text-2xl">Ressources humaines</h1>
          <p className="text-muted">
            Personnel technique, contrats et effectifs d'enquêteurs — sans information salariale.
          </p>
        </div>
        {vue !== 'tableau-de-bord' && (
          <div className="flex flex-wrap gap-2">
            <BoutonExporter href={urlExportDonnees(vue === 'enqueteurs' ? 'effectifs-enqueteurs' : 'personnel')} libelle="Exporter" />
            {peutGerer && (
            <>
            <Bouton variante="secondaire" taille="normale" icone={<IconeImporter />} onClick={() => setImportOuvert(true)}>
              Importer (Excel)
            </Bouton>
            <Bouton
              taille="normale"
              icone={<IconePlus />}
              onClick={() => (vue === 'enqueteurs' ? setModaleEffectif({}) : setModalePersonnel({}))}
            >
              {vue === 'enqueteurs' ? 'Effectif' : 'Nouvel agent'}
            </Bouton>
            </>
            )}
          </div>
        )}
        {importOuvert && (
          <ImportExcelModale
            type={vue === 'enqueteurs' ? 'effectifs-enqueteurs' : 'personnel'}
            titre={vue === 'enqueteurs' ? "Importer des effectifs d'enquêteurs" : 'Importer du personnel technique'}
            onFermer={() => setImportOuvert(false)}
            onImporte={async (rapport) => {
              await queryClient.invalidateQueries({ queryKey: ['rh'] })
              afficherToast(`Import terminé : ${rapport.resume.creations} créé(s), ${rapport.resume.misesAJour} mis à jour.`)
            }}
          />
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex gap-0.5 rounded-[var(--radius-controle)] bg-nested p-0.5 text-sm">
          {VUES.map((v) => (
            <button
              key={v.valeur}
              type="button"
              onClick={() => definirFiltre('vue', v.valeur)}
              className={`rounded-[calc(var(--radius-controle)-2px)] px-3 py-1.5 font-semibold transition-colors ${
                vue === v.valeur ? 'bg-accent text-white' : 'text-muted hover:text-accent'
              }`}
            >
              {v.libelle}
            </button>
          ))}
        </div>

        {vue === 'personnel' && (
          <div className="relative min-w-48 flex-1">
            <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted">
              <IconeRecherche />
            </span>
            <input
              type="search"
              value={recherche}
              onChange={(e) => definirFiltre('q', e.target.value)}
              placeholder="Rechercher un agent, une fonction, un profil…"
              className="w-full rounded-[var(--radius-controle)] border border-border bg-surface py-2 pr-3 pl-9"
            />
          </div>
        )}
      </div>

      {erreur && (
        <div role="alert" className="rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      {echeances && echeances.donnees.length > 0 && vue !== 'tableau-de-bord' && (
        <section className="rounded-[var(--radius-carte)] bg-warn-bg px-4 py-3">
          <p className="flex items-center gap-2 text-sm font-semibold text-warn">
            <IconeCalendrier />
            {echeances.donnees.length} contrat(s) arrivent à échéance dans les 90 jours
          </p>
          <ul className="mt-2 flex flex-col gap-1 text-sm">
            {echeances.donnees.slice(0, 5).map((e) => (
              <li key={e.contrat.uuid} className="flex flex-wrap items-center gap-2">
                <span className="font-semibold">{e.personnel.nomComplet}</span>
                <span className="text-muted">{e.personnel.fonction}</span>
                <span className="chiffres text-muted">
                  {e.contrat.dateFin ? formaterDate(e.contrat.dateFin) : ''}
                  {e.contrat.joursAvantEcheance !== null
                    && (e.contrat.joursAvantEcheance < 0
                      ? ` · échu depuis ${Math.abs(e.contrat.joursAvantEcheance)} j`
                      : ` · dans ${e.contrat.joursAvantEcheance} j`)}
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {isLoading && <Squelette lignes={4} />}
      {isError && <p className="text-bad">Impossible de charger les ressources humaines. Réessayez.</p>}

      {vue === 'personnel' && personnels && (
        <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
          <table className="w-full min-w-[56rem] border-collapse text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted">
                <th className="px-4 py-3 font-semibold">Agent</th>
                <th className="px-4 py-3 font-semibold">Fonction</th>
                <th className="px-4 py-3 font-semibold">Profil</th>
                <th className="px-4 py-3 font-semibold">Sexe</th>
                <th className="px-4 py-3 font-semibold">Opération</th>
                <th className="px-4 py-3 font-semibold">Contrat courant</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {personnelsFiltres.map((p) => (
                <tr key={p.uuid} className="border-b border-border last:border-0 hover:bg-hover">
                  <td className="px-4 py-3 font-semibold">{p.nomComplet}</td>
                  <td className="px-4 py-3">{p.fonction}</td>
                  <td className="px-4 py-3 text-muted">{p.profil ?? '—'}</td>
                  <td className="px-4 py-3 text-muted">{LIBELLES_SEXE[p.sexe]}</td>
                  <td className="px-4 py-3 text-muted">{p.activite ? p.activite.code : '—'}</td>
                  <td className="chiffres px-4 py-3">
                    {p.contratCourant === null ? (
                      <span className="text-muted">—</span>
                    ) : (
                      <>
                        {formaterDate(p.contratCourant.dateDebut)} →{' '}
                        {p.contratCourant.dateFin ? formaterDate(p.contratCourant.dateFin) : 'sans terme'}
                      </>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-1">
                      <Bouton variante="discret" taille="mini" onClick={() => setModaleContrats(p)}>
                        Contrats ({p.contrats.length})
                      </Bouton>
                      {peutGerer && (
                        <>
                          <IconeBouton libelle="Modifier l'agent" onClick={() => setModalePersonnel({ personnel: p })}>
                            <IconeModifier />
                          </IconeBouton>
                          <IconeBouton
                            libelle="Retirer l'agent"
                            disabled={actionEnCours === p.uuid}
                            onClick={async () => {
                        if (await confirmer({ titre: 'Retirer l’agent', message: `${p.nomComplet} sera retiré du personnel technique.`, action: 'Retirer' })) {
                          void executer(p.uuid, () => supprimerPersonnel(p.uuid), 'Agent retiré.')
                        }
                      }}
                            classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                          >
                            <IconeSupprimer />
                          </IconeBouton>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
              {personnelsFiltres.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-muted">
                    {terme === '' ? 'Aucun agent enregistré pour le moment.' : 'Aucun agent ne correspond à la recherche.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {vue === 'enqueteurs' && effectifs && (
        <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
          <table className="w-full min-w-[48rem] border-collapse text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted">
                <th className="px-4 py-3 font-semibold">Opération</th>
                <th className="px-4 py-3 font-semibold">Vague</th>
                <th className="px-4 py-3 font-semibold">Année</th>
                <th className="px-4 py-3 font-semibold">Mobilisation</th>
                <th className="px-4 py-3 text-right font-semibold">Femmes</th>
                <th className="px-4 py-3 text-right font-semibold">Hommes</th>
                <th className="px-4 py-3 text-right font-semibold">Total</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {effectifs.donnees.map((e) => (
                <tr key={e.uuid} className="border-b border-border last:border-0 hover:bg-hover">
                  <td className="px-4 py-3">
                    <span className="chiffres mr-2 text-muted">{e.activite.code}</span>
                    {e.activite.libelle}
                  </td>
                  <td className="px-4 py-3 text-muted">{e.libelle ?? '—'}</td>
                  <td className="chiffres px-4 py-3">{e.annee}</td>
                  <td className="chiffres px-4 py-3 text-muted">
                    {e.dateDebut === null && e.dateFin === null
                      ? 'Non programmée'
                      : `${e.dateDebut ? formaterDate(e.dateDebut) : '?'} → ${e.dateFin ? formaterDate(e.dateFin) : '?'}`}
                  </td>
                  <td className="chiffres px-4 py-3 text-right">{e.nombreFemmes}</td>
                  <td className="chiffres px-4 py-3 text-right">{e.nombreHommes}</td>
                  <td className="chiffres px-4 py-3 text-right font-semibold">{e.effectifTotal}</td>
                  <td className="px-4 py-3">
                    {peutGerer && (
                      <div className="flex items-center justify-end gap-1">
                        <IconeBouton libelle="Modifier l'effectif" onClick={() => setModaleEffectif({ effectif: e })}>
                          <IconeModifier />
                        </IconeBouton>
                        <IconeBouton
                          libelle="Retirer l'effectif"
                          disabled={actionEnCours === e.uuid}
                          onClick={async () => {
                        if (await confirmer({ titre: 'Retirer l’effectif', message: `L’effectif d’enquêteurs ${e.libelle ?? e.activite.code} (${e.annee}) sera retiré.`, action: 'Retirer' })) {
                          void executer(e.uuid, () => supprimerEffectifEnqueteurs(e.uuid), 'Effectif retiré.')
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
              {effectifs.donnees.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-muted">
                    Aucun effectif d'enquêteurs renseigné pour le moment.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {vue === 'tableau-de-bord' && personnels && (
        <TableauBordRh personnels={personnels.donnees} effectifs={effectifs?.donnees ?? []} />
      )}

      {modalePersonnel && (
        <PersonnelModale personnel={modalePersonnel.personnel} onFermer={() => setModalePersonnel(null)} />
      )}
      {modaleContrats && <ContratsPersonnelModale personnel={modaleContrats} onFermer={() => setModaleContrats(null)} />}
      {modaleEffectif && (
        <EffectifEnqueteursModale effectif={modaleEffectif.effectif} onFermer={() => setModaleEffectif(null)} />
      )}
    </div>
  )
}
