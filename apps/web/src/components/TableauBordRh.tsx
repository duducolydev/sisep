import { Bar, BarChart, CartesianGrid, Cell, LabelList, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { COULEURS_SEXE, LIBELLES_SEXE, compterPar, type EffectifEnqueteurs, type Personnel } from '@/lib/rh'

interface TableauBordRhProps {
  personnels: Personnel[]
  effectifs: EffectifEnqueteurs[]
}

/** Capitalisation sur les emplois créés (§ 11 du CR du 22/09/2026) : profils, spécialités et sexes du personnel technique, effectifs d'enquêteurs par opération. */
export function TableauBordRh({ personnels, effectifs }: TableauBordRhProps) {
  const parProfil = compterPar(personnels, (p) => p.profil)
  const parSpecialite = compterPar(personnels, (p) => p.specialite)
  const femmes = personnels.filter((p) => p.sexe === 'F').length
  const hommes = personnels.length - femmes

  const parOperation = effectifs.map((e) => ({
    operation: e.activite.code,
    libelle: e.libelle ? `${e.activite.code} — ${e.libelle}` : e.activite.code,
    Femmes: e.nombreFemmes,
    Hommes: e.nombreHommes,
  }))
  const enqueteursFemmes = effectifs.reduce((somme, e) => somme + e.nombreFemmes, 0)
  const enqueteursHommes = effectifs.reduce((somme, e) => somme + e.nombreHommes, 0)

  return (
    <div className="flex flex-col gap-8">
      <section className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
        <CarteRh libelle="Personnel technique" valeur={personnels.length} sousTexte={`${femmes} F · ${hommes} H`} />
        <CarteRh
          libelle="Contrats en cours"
          valeur={personnels.filter((p) => p.contratCourant !== null).length}
          sousTexte={`${personnels.filter((p) => p.contratCourant === null).length} sans contrat saisi`}
        />
        <CarteRh
          libelle="Enquêteurs mobilisés"
          valeur={enqueteursFemmes + enqueteursHommes}
          sousTexte={`${enqueteursFemmes} F · ${enqueteursHommes} H`}
        />
        <CarteRh
          libelle="Emplois créés"
          valeur={personnels.length + enqueteursFemmes + enqueteursHommes}
          sousTexte="personnel et enquêteurs confondus"
        />
      </section>

      <section>
        <h2 className="mb-1 text-lg font-semibold">Personnel technique par profil</h2>
        <p className="mb-3 text-sm text-muted">Diplôme ou corps déclaré pour chaque agent.</p>
        <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
          <GraphiqueComptage donnees={parProfil} vide="Aucun profil renseigné pour le moment." />
        </div>
      </section>

      <section>
        <h2 className="mb-1 text-lg font-semibold">Personnel technique par spécialité</h2>
        <div className="mt-3 rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
          <GraphiqueComptage donnees={parSpecialite} vide="Aucune spécialité renseignée pour le moment." />
        </div>
      </section>

      <section>
        <h2 className="mb-1 text-lg font-semibold">Enquêteurs par opération</h2>
        <p className="mb-3 text-sm text-muted">Effectifs agrégés, désagrégés par sexe.</p>
        <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
          {parOperation.length === 0 ? (
            <p className="text-muted">Aucun effectif d'enquêteurs renseigné pour le moment.</p>
          ) : (
            <div className="h-72 w-full">
              <ResponsiveContainer width="100%" height="100%" minWidth={200} minHeight={200}>
                <BarChart data={parOperation} margin={{ top: 16, right: 16, bottom: 0, left: 0 }} barCategoryGap="22%">
                  <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="operation" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} />
                  <YAxis stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} allowDecimals={false} />
                  <Tooltip
                    cursor={{ fill: 'var(--color-hover)' }}
                    contentStyle={{
                      background: 'var(--color-surface)',
                      border: '1px solid var(--color-border)',
                      borderRadius: 8,
                      fontSize: 12,
                    }}
                  />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  {/* Empilées : femmes et hommes composent un tout, l'effectif de l'opération. */}
                  <Bar dataKey="Femmes" stackId="effectif" fill={COULEURS_SEXE.F} isAnimationActive={false} maxBarSize={72} />
                  <Bar
                    dataKey="Hommes"
                    stackId="effectif"
                    fill={COULEURS_SEXE.M}
                    isAnimationActive={false}
                    maxBarSize={72}
                    radius={[4, 4, 0, 0]}
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </div>
      </section>

      <section>
        <h2 className="mb-3 text-lg font-semibold">Répartition par sexe</h2>
        <div className="rounded-[var(--radius-carte)] bg-surface p-4 shadow-[var(--shadow-carte)]">
          <ul className="flex flex-col gap-3">
            {(['F', 'M'] as const).map((sexe) => {
              const effectif = sexe === 'F' ? femmes + enqueteursFemmes : hommes + enqueteursHommes
              const total = personnels.length + enqueteursFemmes + enqueteursHommes
              const part = total === 0 ? null : effectif / total

              return (
                <li key={sexe} className="flex items-center gap-3">
                  <span className="w-20 text-sm">{LIBELLES_SEXE[sexe]}</span>
                  <div className="h-2.5 flex-1 overflow-hidden rounded-[var(--radius-barre)] bg-track">
                    <div
                      className="h-full"
                      style={{ width: `${(part ?? 0) * 100}%`, background: COULEURS_SEXE[sexe] }}
                    />
                  </div>
                  <span className="chiffres w-28 text-right text-sm">
                    {effectif} {part === null ? '' : `· ${Math.round(part * 100)} %`}
                  </span>
                </li>
              )
            })}
          </ul>
        </div>
      </section>
    </div>
  )
}

function GraphiqueComptage({ donnees, vide }: { donnees: { libelle: string; nombre: number }[]; vide: string }) {
  if (donnees.length === 0) {
    return <p className="text-muted">{vide}</p>
  }

  return (
    <div style={{ height: Math.max(180, donnees.length * 36 + 40) }} className="w-full">
      <ResponsiveContainer width="100%" height="100%" minWidth={200} minHeight={160}>
        <BarChart data={donnees} layout="vertical" margin={{ top: 4, right: 40, bottom: 4, left: 8 }}>
          <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" horizontal={false} />
          <XAxis type="number" stroke="var(--color-muted)" fontSize={12} tickLine={false} axisLine={false} allowDecimals={false} />
          <YAxis
            type="category"
            dataKey="libelle"
            stroke="var(--color-muted)"
            fontSize={12}
            tickLine={false}
            axisLine={false}
            width={150}
          />
          <Bar dataKey="nombre" radius={[0, 4, 4, 0]} maxBarSize={24} isAnimationActive={false}>
            {donnees.map((d) => (
              <Cell key={d.libelle} fill="var(--color-serie-1)" />
            ))}
            <LabelList dataKey="nombre" position="right" fill="var(--color-text)" fontSize={12} fontWeight={600} />
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

function CarteRh({ libelle, valeur, sousTexte }: { libelle: string; valeur: number; sousTexte: string }) {
  return (
    <div className="relative min-w-0 overflow-hidden rounded-[var(--radius-carte)] bg-surface p-4 pl-5 shadow-[var(--shadow-carte)]">
      <span className="absolute inset-y-0 left-0 w-[3px] bg-accent" aria-hidden="true" />
      <p className="truncate text-[10.5px] font-semibold uppercase tracking-[.13em] text-muted">{libelle}</p>
      <p className="chiffres mt-1.5 mb-0.5 text-[26px] font-extrabold tracking-tight">{valeur}</p>
      <p className="truncate text-xs text-muted">{sousTexte}</p>
    </div>
  )
}
