import { useQuery } from '@tanstack/react-query'
import { rapportsQueryOptions } from '@/lib/parties-prenantes'
import { usePeutSurStructure } from '@/lib/use-permissions'
import { ListeRapports } from '@/components/ListeRapports'
import { Squelette } from '@/components/Squelette'

/**
 * Rapports périodiques des structures dont l'utilisateur est le point focal
 * — et rien d'autre du module Parties prenantes, qui lui est fermé
 * (décision du 26/10/2026). Le serveur ne renvoie que ces rapports-là ; les
 * courriels de relance pointent ici.
 */
export function MesRapports() {
  const { data, isLoading, isError } = useQuery(rapportsQueryOptions())
  const peutTransmettreSur = usePeutSurStructure('rapports.transmettre')

  const rapports = [...(data?.donnees ?? [])].sort((a, b) => b.dateEcheance.localeCompare(a.dateEcheance))
  const plusieursStructures = new Set(rapports.map((r) => r.structure.uuid)).size > 1
  const enRetard = rapports.filter((r) => r.enRetard).length

  return (
    <div className="flex flex-col gap-6">
      <div>
        <p className="font-titre text-eyebrow uppercase tracking-wide">Reporting trimestriel</p>
        <h1 className="text-2xl">Mes rapports</h1>
        <p className="text-muted">
          Rapports périodiques attendus de votre structure. Cliquez sur « Transmettre » et choisissez le fichier du rapport
          (10 Mo au plus) : l'UGP le retrouve aussitôt dans le suivi des parties prenantes.
        </p>
      </div>

      {enRetard > 0 && (
        <div role="status" className="rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {enRetard === 1 ? 'Un rapport est en retard.' : `${enRetard} rapports sont en retard.`}
        </div>
      )}

      {isLoading ? (
        <Squelette lignes={4} />
      ) : isError ? (
        <p className="text-bad">Impossible de charger vos rapports. Réessayez.</p>
      ) : (
        <ListeRapports
          rapports={rapports}
          peutTransmettre={(r) => peutTransmettreSur(r.structure.uuid)}
          avecStructure={plusieursStructures}
        />
      )}
    </div>
  )
}
