import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Modale } from './Modale'
import { Pastille } from './Pastille'
import { Squelette } from './Squelette'
import { activiteQueryOptions } from '@/lib/composantes'
import { formaterDate } from '@/lib/format'

interface SousActiviteModaleProps {
  activiteUuid: string
  sousActiviteUuid: string
  onFermer: () => void
}

/** Détail d'une sous-activité, ouvert depuis l'arbre d'exécution — § 2.2, § 6.2 du brief. */
export function SousActiviteModale({ activiteUuid, sousActiviteUuid, onFermer }: SousActiviteModaleProps) {
  const { data: activite, isLoading } = useQuery(activiteQueryOptions(activiteUuid))

  const sousActivite = activite?.sousActivites?.find((sa) => sa.uuid === sousActiviteUuid)

  return (
    <Modale titre={sousActivite ? sousActivite.libelle : 'Sous-activité'} onFermer={onFermer}>
      {isLoading && <Squelette lignes={2} />}
      {!isLoading && !sousActivite && <p className="text-bad">Sous-activité introuvable.</p>}

      {activite && sousActivite && (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-muted">
            <span className="chiffres mr-1">{activite.code}</span>
            {activite.libelle}
          </p>

          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted">Responsable</dt>
            <dd>{sousActivite.structureResponsable?.sigle ?? activite.structureResponsable.sigle}</dd>

            <dt className="text-muted">Statut</dt>
            <dd>
              <Pastille statut={sousActivite.statut} />
            </dd>

            <dt className="text-muted">Échéance</dt>
            <dd className="chiffres">{sousActivite.echeance ? formaterDate(sousActivite.echeance) : 'Sans échéance'}</dd>
          </dl>

          <Link to={`/composantes/activites/${activite.uuid}`} className="text-sm text-accent" onClick={onFermer}>
            Voir la fiche complète de l'activité
          </Link>
        </div>
      )}
    </Modale>
  )
}
