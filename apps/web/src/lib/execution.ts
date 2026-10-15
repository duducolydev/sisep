import { queryOptions } from '@tanstack/react-query'
import { apiClient } from './api-client'

/** Grandeurs d'exécution d'un périmètre — calculées par le serveur, jamais stockées ; taux `null` quand le dénominateur est nul. */
export interface MesuresExecution {
  /** Unités physiques : lignes du PTBA, ou activités sans ligne. */
  unites: number
  realisees: number
  tauxPhysique: number | null
  /** Moyenne de l'avancement des unités structurées en étapes (0-1). */
  avancementMoyen: number | null
  budget: number
  /** Cumulés depuis le début de l'exercice jusqu'à la fin de la période. */
  engage: number
  depense: number
  /** Montants de la période seule. */
  engagePeriode: number
  depensePeriode: number
  tauxEngagement: number | null
  tauxExecution: number | null
  tauxDecaissement: number | null
}

export interface LigneExecutionSynthese extends MesuresExecution {
  uuid: string
  code: string
  libelle: string
}

export interface ExecutionSynthese {
  exercice: number
  periode: { debut: string; fin: string }
  /** Niveau du détail : un cran sous la sélection. */
  niveau: 'composante' | 'activite' | 'ligne'
  totaux: MesuresExecution
  lignes: LigneExecutionSynthese[]
  options: {
    composantes: { uuid: string; code: string; libelle: string }[]
    activites: { uuid: string; code: string; libelle: string; composanteUuid: string }[]
    /** Lignes du PTBA consultables, pour la recherche directe par nom (RNOCB, ENES…). */
    lignes: { uuid: string; libelle: string; activiteUuid: string; activiteCode: string }[]
  }
}

export interface FiltresExecution {
  exercice?: string
  trimestre?: string
  mois?: string
  composante?: string
  activite?: string
  ligne?: string
}

export const executionQueryOptions = (projetUuid: string | undefined, filtres: FiltresExecution) =>
  queryOptions({
    queryKey: ['execution', projetUuid, filtres],
    queryFn: () => {
      const params = new URLSearchParams()
      for (const [cle, valeur] of Object.entries(filtres)) {
        if (valeur !== undefined && valeur !== '') params.set(cle, valeur)
      }
      const suffixe = params.toString()

      return apiClient.get<ExecutionSynthese>(`/projets/${projetUuid}/execution${suffixe ? `?${suffixe}` : ''}`)
    },
    enabled: projetUuid !== undefined,
  })
