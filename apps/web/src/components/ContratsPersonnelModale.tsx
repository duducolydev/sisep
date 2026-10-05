import { useState } from 'react'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { IconeBouton, IconePlus, IconeSupprimer } from './Icones'
import { TYPE_CONTRAT_PAR_DEFAUT, creerContrat, supprimerContrat, type Personnel } from '@/lib/rh'
import { formaterDate } from '@/lib/format'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface ContratsPersonnelModaleProps {
  personnel: Personnel
  onFermer: () => void
}

/**
 * Contrats d'un agent (§ 10 du CR du 22/09/2026). Un renouvellement se
 * saisit comme un nouveau contrat : l'ancien reste, c'est ce qui permet de
 * reconstituer l'historique des périodes contractuelles.
 */
export function ContratsPersonnelModale({ personnel, onFermer }: ContratsPersonnelModaleProps) {
  const [dateDebut, setDateDebut] = useState('')
  const [dateFin, setDateFin] = useState('')
  const [type, setType] = useState(TYPE_CONTRAT_PAR_DEFAUT)
  const [formulaireOuvert, setFormulaireOuvert] = useState(personnel.contrats.length === 0)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  async function executer(action: () => Promise<unknown>) {
    setErreur(null)
    setEnCours(true)
    try {
      await action()
      await queryClient.invalidateQueries({ queryKey: ['rh'] })
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  function libelleEcheance(jours: number | null): string {
    if (jours === null) return 'Sans terme'
    if (jours < 0) return `Échu depuis ${Math.abs(jours)} j`

    return `Échéance dans ${jours} j`
  }

  return (
    <Modale titre={`Contrats — ${personnel.nomComplet}`} onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      {personnel.contrats.length === 0 ? (
        <p className="mb-4 text-sm text-muted">Aucun contrat enregistré pour cet agent.</p>
      ) : (
        <ul className="mb-4 flex flex-col gap-2">
          {personnel.contrats.map((contrat) => (
            <li
              key={contrat.uuid}
              className="flex flex-wrap items-center justify-between gap-2 rounded-[var(--radius-controle)] border border-border bg-nested px-3 py-2 text-sm"
            >
              <span className="chiffres">
                {formaterDate(contrat.dateDebut)} → {contrat.dateFin ? formaterDate(contrat.dateFin) : '—'}
              </span>
              <span className="flex items-center gap-2">
                {contrat.type && <span className="text-muted">{contrat.type}</span>}
                <span
                  className={`chiffres text-xs ${
                    contrat.joursAvantEcheance !== null && contrat.joursAvantEcheance < 30 ? 'text-warn' : 'text-muted'
                  }`}
                >
                  {libelleEcheance(contrat.joursAvantEcheance)}
                </span>
                <IconeBouton
                  libelle="Retirer le contrat"
                  disabled={enCours}
                  onClick={() => void executer(() => supprimerContrat(contrat.uuid))}
                  classeCouleur="text-muted hover:bg-bad-bg hover:text-bad"
                >
                  <IconeSupprimer />
                </IconeBouton>
              </span>
            </li>
          ))}
        </ul>
      )}

      {formulaireOuvert ? (
        <div className="flex flex-col gap-3 rounded-[var(--radius-controle)] border border-border p-3">
          <p className="text-sm font-semibold">Nouveau contrat</p>
          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1 text-sm">
              <label htmlFor="contrat-debut">Date de début</label>
              <input
                id="contrat-debut"
                type="date"
                value={dateDebut}
                disabled={enCours}
                onChange={(e) => setDateDebut(e.target.value)}
                className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              />
            </div>
            <div className="flex flex-col gap-1 text-sm">
              <label htmlFor="contrat-fin">Date de fin (optionnel)</label>
              <input
                id="contrat-fin"
                type="date"
                value={dateFin}
                disabled={enCours}
                onChange={(e) => setDateFin(e.target.value)}
                className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
              />
            </div>
          </div>
          <div className="flex flex-col gap-1 text-sm">
            <label htmlFor="contrat-type">Type</label>
            <input
              id="contrat-type"
              value={type}
              disabled={enCours}
              onChange={(e) => setType(e.target.value)}
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
            <span className="text-xs text-muted">
              Au PHASAOC, tout le personnel technique est sous contrat de prestation : la valeur est pré-remplie.
            </span>
          </div>
          <div className="flex justify-end gap-2">
            {personnel.contrats.length > 0 && (
              <Bouton variante="secondaire" taille="mini" onClick={() => setFormulaireOuvert(false)}>
                Annuler
              </Bouton>
            )}
            <Bouton
              taille="mini"
              disabled={enCours || dateDebut === ''}
              onClick={() =>
                void executer(async () => {
                  await creerContrat(personnel.uuid, {
                    dateDebut,
                    dateFin: dateFin === '' ? null : dateFin,
                    type: type.trim() === '' ? null : type.trim(),
                  })
                  setDateDebut('')
                  setDateFin('')
                  setType(TYPE_CONTRAT_PAR_DEFAUT)
                  setFormulaireOuvert(false)
                })
              }
            >
              Enregistrer le contrat
            </Bouton>
          </div>
        </div>
      ) : (
        <Bouton variante="discret" taille="mini" icone={<IconePlus />} onClick={() => setFormulaireOuvert(true)}>
          Nouveau contrat (renouvellement)
        </Bouton>
      )}

      <div className="mt-5 flex justify-end border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Fermer
        </Bouton>
      </div>
    </Modale>
  )
}
