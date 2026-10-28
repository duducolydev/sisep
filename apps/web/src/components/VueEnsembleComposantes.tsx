import { useMemo } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { progressionComposante, type NoeudComposante } from '@/lib/composantes'
import { formaterFcfa, formaterMds, formaterTaux } from '@/lib/format'
import { GraphiqueExportable } from './GraphiqueExportable'

interface VueEnsembleComposantesProps {
  composantes: NoeudComposante[]
  /** Part de l'exercice écoulée (0-1) — repère de rythme attendu, `null` hors exercice. */
  tauxAttendu?: number | null
}

interface LigneComposante {
  uuid: string
  code: string
  libelle: string
  notifie: number
  depense: number
  /** Engagé non encore décaissé — la part qui s'ajoute au décaissé pour faire l'engagé total. */
  engageNonDepense: number
  disponible: number
  /** `null` quand rien n'est notifié : une division par zéro n'est pas un taux de 0 (§ 5.1). */
  tauxExecution: number | null
  /** Moyenne des progressions d'activités, en %, `null` si aucune activité n'est structurée en étapes. */
  progression: number | null
  activitesSuivies: number
}

/**
 * Vue d'ensemble des composantes : trois repères chiffrés, puis l'exécution
 * budgétaire et la progression physique côte à côte.
 *
 * Deux graphiques et non un seul : les montants sont en FCFA, la progression
 * en pourcentage. Les superposer imposerait deux axes verticaux, ce qui fait
 * lire une proximité de courbes qui ne veut rien dire. Même ordre de
 * composantes dans les deux, pour que l'œil passe d'un graphique à l'autre
 * sur la même ligne de lecture.
 *
 * Les barres sont horizontales : un code de composante tient, son libellé
 * non, et une étiquette inclinée se lit mal.
 *
 * L'arbre détaillé en dessous tient le rôle de vue tabulaire : tout ce que
 * ces graphiques résument y figure chiffre par chiffre.
 */
export function VueEnsembleComposantes({ composantes, tauxAttendu }: VueEnsembleComposantesProps) {
  const lignes = useMemo(() => composantes.map(construireLigne), [composantes])

  if (lignes.length === 0) {
    return null
  }

  const notifieTotal = lignes.reduce((somme, l) => somme + l.notifie, 0)
  const depenseTotal = lignes.reduce((somme, l) => somme + l.depense, 0)
  const tauxGlobal = notifieTotal === 0 ? null : depenseTotal / notifieTotal

  const suivies = lignes.filter((l) => l.progression !== null)
  const activitesSuivies = lignes.reduce((somme, l) => somme + l.activitesSuivies, 0)
  const progressionMoyenne =
    suivies.length === 0 ? null : suivies.reduce((somme, l) => somme + (l.progression ?? 0), 0) / suivies.length

  return (
    <section className="mb-6" aria-label="Vue d'ensemble des composantes">
      <div className="mb-4 grid gap-4 sm:grid-cols-3">
        <Repere libelle="Budget notifié" valeur={formaterMds(notifieTotal)} unite="Mds FCFA" precision={formaterFcfa(notifieTotal)} />
        <Repere
          libelle="Taux d'exécution"
          valeur={formaterTaux(tauxGlobal)}
          precision={
            tauxAttendu === null || tauxAttendu === undefined
              ? `${formaterFcfa(depenseTotal)} décaissés`
              : `rythme attendu à ce jour : ${formaterTaux(tauxAttendu)}`
          }
        />
        <Repere
          libelle="Progression des étapes"
          valeur={progressionMoyenne === null ? '—' : `${Math.round(progressionMoyenne)} %`}
          precision={
            progressionMoyenne === null
              ? 'aucune activité structurée en étapes'
              : `moyenne sur ${activitesSuivies} activité${activitesSuivies > 1 ? 's' : ''} suivie${activitesSuivies > 1 ? 's' : ''}`
          }
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Carte titre="Exécution budgétaire par composante" sousTitre="Dépensé, engagé non décaissé et disponible, sur le budget notifié (Mds FCFA)">
          <GraphiqueExecution lignes={lignes} />
          <Legende
            entrees={[
              { couleur: 'bg-accent', libelle: 'Dépensé' },
              { couleur: 'bg-accent-clair', libelle: 'Engagé non décaissé' },
              { couleur: 'bg-track', libelle: 'Disponible' },
            ]}
          />
        </Carte>

        <Carte titre="Progression des étapes par composante" sousTitre="Moyenne des progressions d'activités, déduite des étapes renseignées">
          <GraphiqueProgression lignes={lignes} />
        </Carte>
      </div>
    </section>
  )
}

function construireLigne(composante: NoeudComposante): LigneComposante {
  const notifie = composante.notifie ?? 0
  const engage = composante.engage ?? 0
  const depense = composante.depense ?? 0

  const { progression, suivies } = progressionComposante(composante)

  return {
    uuid: composante.uuid,
    code: composante.code,
    libelle: composante.libelle,
    notifie,
    depense,
    engageNonDepense: Math.max(0, engage - depense),
    disponible: Math.max(0, notifie - engage),
    tauxExecution: notifie === 0 ? null : depense / notifie,
    progression,
    activitesSuivies: suivies,
  }
}

function GraphiqueExecution({ lignes }: { lignes: LigneComposante[] }) {
  return (
    <div style={{ height: `${Math.max(160, lignes.length * 48 + 40)}px` }} className="w-full">
      <ResponsiveContainer width="100%" height="100%" minWidth={200}>
        <BarChart data={lignes} layout="vertical" margin={{ top: 4, right: 96, bottom: 4, left: 4 }} barCategoryGap="34%">
          {/* Grille en trait plein d'un cran au-dessus de la surface : elle sert de repère, pas de décor. */}
          <CartesianGrid stroke="var(--color-border)" horizontal={false} />
          <XAxis
            type="number"
            stroke="var(--color-muted)"
            fontSize={11}
            tickLine={false}
            axisLine={false}
            tickFormatter={(v: number) => formaterMds(v)}
          />
          <YAxis
            type="category"
            dataKey="code"
            width={44}
            stroke="var(--color-muted)"
            fontSize={12}
            tickLine={false}
            axisLine={false}
          />
          <Tooltip content={<InfobulleExecution />} cursor={{ fill: 'var(--color-hover)' }} />
          {/*
            Les segments se touchent : le séparateur est un trait de 2px de la
            couleur de la surface, donc du vide, pas une bordure encrée.
            L'extrémité de la pile est arrondie, la base reste carrée.
          */}
          <Bar dataKey="depense" name="Dépensé" stackId="budget" fill="var(--color-accent)" stroke="var(--color-surface)" strokeWidth={2} maxBarSize={24} isAnimationActive={false} />
          <Bar
            dataKey="engageNonDepense"
            name="Engagé non décaissé"
            stackId="budget"
            fill="var(--color-accent-clair)"
            stroke="var(--color-surface)"
            strokeWidth={2}
            maxBarSize={24}
            isAnimationActive={false}
          />
          <Bar
            dataKey="disponible"
            name="Disponible"
            stackId="budget"
            fill="var(--color-track)"
            stroke="var(--color-surface)"
            strokeWidth={2}
            radius={[0, 4, 4, 0]}
            maxBarSize={24}
            isAnimationActive={false}
          >
            {/* Une seule étiquette par barre, au bout de la pile : le taux, qui est ce qu'on vient lire. */}
            <LabelList
              dataKey="tauxExecution"
              position="right"
              offset={10}
              formatter={(v: unknown) => `${formaterTaux(v === null || v === undefined ? null : Number(v))} exécuté`}
              fill="var(--color-muted)"
              fontSize={11}
            />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

function GraphiqueProgression({ lignes }: { lignes: LigneComposante[] }) {
  return (
    <div style={{ height: `${Math.max(160, lignes.length * 48 + 40)}px` }} className="w-full">
      <ResponsiveContainer width="100%" height="100%" minWidth={200}>
        <BarChart data={lignes} layout="vertical" margin={{ top: 4, right: 72, bottom: 4, left: 4 }} barCategoryGap="34%">
          <CartesianGrid stroke="var(--color-border)" horizontal={false} />
          <XAxis
            type="number"
            domain={[0, 100]}
            ticks={[0, 25, 50, 75, 100]}
            stroke="var(--color-muted)"
            fontSize={11}
            tickLine={false}
            axisLine={false}
            tickFormatter={(v: number) => `${v} %`}
          />
          <YAxis
            type="category"
            dataKey="code"
            width={44}
            stroke="var(--color-muted)"
            fontSize={12}
            tickLine={false}
            axisLine={false}
          />
          <Tooltip content={<InfobulleProgression />} cursor={{ fill: 'var(--color-hover)' }} />
          {/* Série unique : pas de boîte de légende, le titre de la carte dit ce qui est tracé. */}
          <Bar dataKey="progression" name="Progression" radius={[0, 4, 4, 0]} maxBarSize={24} isAnimationActive={false}>
            {lignes.map((ligne) => (
              // La couleur suit la composante, jamais son rang : une composante
              // sans étape suivie reste en piste, elle ne devient pas verte.
              <Cell key={ligne.uuid} fill={ligne.progression === null ? 'var(--color-track)' : 'var(--color-success)'} />
            ))}
            <LabelList
              dataKey="progression"
              position="right"
              offset={10}
              formatter={(v: unknown) =>
                v === null || v === undefined ? 'non structurée' : `${Math.round(Number(v))} %`
              }
              fill="var(--color-muted)"
              fontSize={11}
            />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

interface InfobulleProps {
  active?: boolean
  payload?: { payload: LigneComposante }[]
}

function InfobulleExecution({ active, payload }: InfobulleProps) {
  const ligne = active ? payload?.[0]?.payload : undefined
  if (ligne === undefined) {
    return null
  }

  return (
    <Infobulle titre={`${ligne.code} — ${ligne.libelle}`}>
      <Valeur libelle="Notifié" valeur={formaterFcfa(ligne.notifie)} />
      <Valeur libelle="Dépensé" valeur={formaterFcfa(ligne.depense)} />
      <Valeur libelle="Engagé non décaissé" valeur={formaterFcfa(ligne.engageNonDepense)} />
      <Valeur libelle="Disponible" valeur={formaterFcfa(ligne.disponible)} />
      <Valeur libelle="Taux d'exécution" valeur={formaterTaux(ligne.tauxExecution)} />
    </Infobulle>
  )
}

function InfobulleProgression({ active, payload }: InfobulleProps) {
  const ligne = active ? payload?.[0]?.payload : undefined
  if (ligne === undefined) {
    return null
  }

  return (
    <Infobulle titre={`${ligne.code} — ${ligne.libelle}`}>
      <Valeur
        libelle="Progression"
        valeur={ligne.progression === null ? 'non structurée en étapes' : `${Math.round(ligne.progression)} %`}
      />
      <Valeur libelle="Activités suivies" valeur={String(ligne.activitesSuivies)} />
    </Infobulle>
  )
}

function Infobulle({ titre, children }: { titre: string; children: React.ReactNode }) {
  return (
    <div className="max-w-xs rounded-[var(--radius-controle)] border border-border bg-surface px-3 py-2 text-xs shadow-[var(--shadow-carte)]">
      <p className="mb-1 font-semibold text-text">{titre}</p>
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">{children}</dl>
    </div>
  )
}

function Valeur({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <>
      <dt className="text-muted">{libelle}</dt>
      <dd className="chiffres text-right text-text">{valeur}</dd>
    </>
  )
}

function Carte({ titre, sousTitre, children }: { titre: string; sousTitre: string; children: React.ReactNode }) {
  return (
    <GraphiqueExportable titre={titre} titreDansLeCadre className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
      <h2 className="pr-20 text-base font-semibold">{titre}</h2>
      <p className="mb-3 text-xs text-muted">{sousTitre}</p>
      {children}
    </GraphiqueExportable>
  )
}

function Legende({ entrees }: { entrees: { couleur: string; libelle: string }[] }) {
  return (
    <div className="mt-2 flex flex-wrap items-center gap-4 text-xs text-muted">
      {entrees.map((e) => (
        <span key={e.libelle} className="flex items-center gap-1.5">
          <span className={`inline-block h-2.5 w-2.5 rounded-sm ${e.couleur}`} aria-hidden="true" />
          {e.libelle}
        </span>
      ))}
    </div>
  )
}

function Repere({
  libelle,
  valeur,
  unite,
  precision,
}: {
  libelle: string
  valeur: string
  unite?: string
  precision: string
}) {
  return (
    <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
      <p className="text-xs uppercase tracking-wide text-muted">{libelle}</p>
      <p className="chiffres mt-1 text-2xl font-semibold text-text">
        {valeur}
        {unite !== undefined && <span className="ml-1 text-sm font-normal text-muted">{unite}</span>}
      </p>
      <p className="mt-1 text-xs text-muted">{precision}</p>
    </div>
  )
}
