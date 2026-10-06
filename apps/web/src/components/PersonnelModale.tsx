import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Modale } from './Modale'
import { Bouton } from './Bouton'
import { ComboboxSimple } from './ComboboxSimple'
import { creerPersonnel, exerciceParDefaut, modifierPersonnel, type Personnel, type Sexe } from '@/lib/rh'
import { structuresQueryOptions } from '@/lib/composantes'
import { budgetExecutionQueryOptions } from '@/lib/budget'
import { ApiError } from '@/lib/api-client'
import { queryClient } from '@/lib/query-client'

interface PersonnelModaleProps {
  /** `undefined` = création. */
  personnel?: Personnel
  onFermer: () => void
}

/** Fiche d'un agent du personnel technique (§ 10 du CR du 22/09/2026) — aucune rubrique salariale : ces informations sont écartées du système. */
export function PersonnelModale({ personnel, onFermer }: PersonnelModaleProps) {
  const { data: structures } = useQuery(structuresQueryOptions())
  const { data: activites } = useQuery(budgetExecutionQueryOptions('activite'))

  const [nom, setNom] = useState(personnel?.nom ?? '')
  const [prenom, setPrenom] = useState(personnel?.prenom ?? '')
  const [sexe, setSexe] = useState<Sexe>(personnel?.sexe ?? 'F')
  const [fonction, setFonction] = useState(personnel?.fonction ?? '')
  const [profil, setProfil] = useState(personnel?.profil ?? '')
  const [specialite, setSpecialite] = useState(personnel?.specialite ?? '')
  const [email, setEmail] = useState(personnel?.email ?? '')
  const [telephone, setTelephone] = useState(personnel?.telephone ?? '')
  const [structure, setStructure] = useState(personnel?.structure?.uuid ?? '')
  const [activite, setActivite] = useState(personnel?.activite?.uuid ?? '')
  // Dates du contrat en cours, saisies ici parce que c'est l'information que
  // l'UGP a sous la main au moment où elle crée la fiche. L'écran des
  // contrats reste le seul endroit où l'on renouvelle.
  const periodeDefaut = exerciceParDefaut()
  const [dateDebut, setDateDebut] = useState(personnel?.contratCourant?.dateDebut ?? periodeDefaut.dateDebut)
  const [dateFin, setDateFin] = useState(personnel?.contratCourant?.dateFin ?? periodeDefaut.dateFin)
  const [enCours, setEnCours] = useState(false)
  const [erreur, setErreur] = useState<string | null>(null)

  async function enregistrer() {
    if (nom.trim() === '' || prenom.trim() === '' || fonction.trim() === '') {
      setErreur('Le nom, le prénom et la fonction sont requis.')

      return
    }
    if (dateDebut === '') {
      setErreur('La date de début de contrat est requise.')

      return
    }
    if (dateFin !== '' && dateFin < dateDebut) {
      setErreur('La date de fin ne peut pas précéder la date de début.')

      return
    }

    setErreur(null)
    setEnCours(true)
    const entree = {
      nom: nom.trim(),
      prenom: prenom.trim(),
      sexe,
      fonction: fonction.trim(),
      profil: profil.trim() === '' ? null : profil.trim(),
      specialite: specialite.trim() === '' ? null : specialite.trim(),
      email: email.trim() === '' ? null : email.trim(),
      telephone: telephone.trim() === '' ? null : telephone.trim(),
      structure: structure === '' ? null : structure,
      activite: activite === '' ? null : activite,
      contrat: { dateDebut, dateFin: dateFin === '' ? null : dateFin },
    }

    try {
      if (personnel) {
        await modifierPersonnel(personnel.uuid, entree)
      } else {
        await creerPersonnel(entree)
      }
      await queryClient.invalidateQueries({ queryKey: ['rh'] })
      onFermer()
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
    } finally {
      setEnCours(false)
    }
  }

  return (
    <Modale titre={personnel ? "Modifier l'agent" : 'Nouvel agent'} taille="large" onFermer={onFermer}>
      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-prenom">Prénom</label>
            <input
              id="rh-prenom"
              value={prenom}
              disabled={enCours}
              onChange={(e) => setPrenom(e.target.value)}
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-nom">Nom</label>
            <input
              id="rh-nom"
              value={nom}
              disabled={enCours}
              onChange={(e) => setNom(e.target.value)}
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-sexe">Sexe</label>
            <select
              id="rh-sexe"
              value={sexe}
              disabled={enCours}
              onChange={(e) => setSexe(e.target.value as Sexe)}
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            >
              <option value="F">Féminin</option>
              <option value="M">Masculin</option>
            </select>
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-profil">Profil (optionnel)</label>
            <input
              id="rh-profil"
              value={profil}
              disabled={enCours}
              onChange={(e) => setProfil(e.target.value)}
              placeholder="ex. ISE, ITS, AS"
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-fonction">Fonction</label>
            <input
              id="rh-fonction"
              value={fonction}
              disabled={enCours}
              onChange={(e) => setFonction(e.target.value)}
              placeholder="ex. Chargé d'étude junior"
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-specialite">Spécialité (optionnel)</label>
            <input
              id="rh-specialite"
              value={specialite}
              disabled={enCours}
              onChange={(e) => setSpecialite(e.target.value)}
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-activite">Opération de rattachement (optionnel)</label>
            <ComboboxSimple
              id="rh-activite"
              options={(activites?.donnees ?? []).map((a) => ({ value: a.uuid, label: `${a.code} — ${a.libelle}` }))}
              valeur={activite}
              onChange={setActivite}
              placeholder="Aucune"
              disabled={enCours}
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-structure">Structure (optionnel)</label>
            <select
              id="rh-structure"
              value={structure}
              disabled={enCours}
              onChange={(e) => setStructure(e.target.value)}
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            >
              <option value="">Aucune</option>
              {(structures?.donnees ?? []).map((s) => (
                <option key={s.uuid} value={s.uuid}>
                  {s.sigle}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-contrat-debut">Début de contrat</label>
            <input
              id="rh-contrat-debut"
              type="date"
              value={dateDebut}
              disabled={enCours}
              onChange={(e) => setDateDebut(e.target.value)}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-contrat-fin">Fin de contrat</label>
            <input
              id="rh-contrat-fin"
              type="date"
              value={dateFin}
              disabled={enCours}
              onChange={(e) => setDateFin(e.target.value)}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
            <p className="text-xs text-muted">Laisser vide pour un contrat sans terme.</p>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-email">Courriel (optionnel)</label>
            <input
              id="rh-email"
              type="email"
              value={email}
              disabled={enCours}
              onChange={(e) => setEmail(e.target.value)}
              className="rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="rh-telephone">Téléphone (optionnel)</label>
            <input
              id="rh-telephone"
              value={telephone}
              disabled={enCours}
              onChange={(e) => setTelephone(e.target.value)}
              className="chiffres rounded-[var(--radius-controle)] border border-border px-3 py-2"
            />
          </div>
        </div>
      </div>

      <div className="mt-5 flex justify-end gap-3 border-t border-border pt-4">
        <Bouton variante="secondaire" onClick={onFermer}>
          Annuler
        </Bouton>
        <Bouton onClick={() => void enregistrer()} disabled={enCours}>
          {enCours ? 'Enregistrement…' : 'Enregistrer'}
        </Bouton>
      </div>
    </Modale>
  )
}
