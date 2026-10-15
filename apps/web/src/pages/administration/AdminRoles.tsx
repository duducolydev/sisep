import { Fragment, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { rolesQueryOptions, permissionsQueryOptions, modifierRolePermissions, type Permission } from '@/lib/admin'
import { queryClient } from '@/lib/query-client'
import { ApiError } from '@/lib/api-client'
import { AdminOnglets } from '@/components/AdminOnglets'
import { Squelette } from '@/components/Squelette'
import { urlExportDonnees } from '@/lib/exports'
import { BoutonExporter } from '@/components/BoutonExporter'
import { usePeut } from '@/lib/use-permissions'

export function AdminRoles() {
  const peutExporterRoles = usePeut('admin.roles')
  const { data: roles, isLoading, isError } = useQuery(rolesQueryOptions())
  const { data: permissions } = useQuery(permissionsQueryOptions())
  const [enCours, setEnCours] = useState<string | null>(null)
  const [erreur, setErreur] = useState<string | null>(null)

  const groupes = new Map<string, Permission[]>()
  for (const permission of permissions?.donnees ?? []) {
    const liste = groupes.get(permission.groupe) ?? []
    liste.push(permission)
    groupes.set(permission.groupe, liste)
  }

  async function basculer(roleUuid: string, permissionsActuelles: string[], code: string, accorder: boolean) {
    setErreur(null)
    const cle = `${roleUuid}:${code}`
    setEnCours(cle)
    const suivantes = accorder ? [...permissionsActuelles, code] : permissionsActuelles.filter((p) => p !== code)

    // Optimiste (§ 8.2 du brief) : bascule immédiate, retour arrière si l'API refuse.
    queryClient.setQueryData(rolesQueryOptions().queryKey, (precedent: typeof roles) =>
      precedent
        ? { donnees: precedent.donnees.map((r) => (r.uuid === roleUuid ? { ...r, permissions: suivantes } : r)) }
        : precedent,
    )

    try {
      await modifierRolePermissions(roleUuid, suivantes)
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez.')
      await queryClient.invalidateQueries({ queryKey: rolesQueryOptions().queryKey })
    } finally {
      setEnCours(null)
    }
  }

  return (
    <div>
      <p className="font-titre text-eyebrow uppercase tracking-wide">Backoffice</p>
      <h1 className="text-2xl">Administration</h1>
      <p className="mb-6 text-muted">Référentiels, parties prenantes, rôles et permissions, journal des actions.</p>

      <AdminOnglets />

      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">Rôles et permissions</h2>
        {peutExporterRoles && <BoutonExporter href={urlExportDonnees('roles')} libelle="Exporter" />}
      </div>

      {erreur && (
        <div role="alert" className="mb-4 rounded-[var(--radius-controle)] bg-bad-bg px-4 py-3 text-bad">
          {erreur}
        </div>
      )}

      {isLoading && <Squelette lignes={4} />}
      {isError && <p className="text-bad">Impossible de charger les rôles. Réessayez.</p>}

      {roles && permissions && (
        <div className="overflow-x-auto rounded-[var(--radius-carte)] bg-surface shadow-[var(--shadow-carte)]">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border">
                <th className="px-4 py-3 text-left font-normal text-muted">Permission</th>
                {roles.donnees.map((role) => (
                  <th key={role.uuid} className="px-3 py-3 text-center text-xs font-normal text-muted">
                    {role.libelle}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {[...groupes.entries()].map(([groupe, permissionsDuGroupe]) => (
                <Fragment key={groupe}>
                  <tr className="bg-nested">
                    <td colSpan={1 + roles.donnees.length} className="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-eyebrow">
                      {groupe}
                    </td>
                  </tr>
                  {permissionsDuGroupe.map((permission) => (
                    <tr key={permission.uuid} className="border-b border-border last:border-0">
                      <td className="px-4 py-2.5">{permission.libelle}</td>
                      {roles.donnees.map((role) => {
                        const accordee = role.permissions.includes(permission.code)
                        const cle = `${role.uuid}:${permission.code}`
                        return (
                          <td key={role.uuid} className="px-3 py-2.5 text-center">
                            <button
                              type="button"
                              role="switch"
                              aria-checked={accordee}
                              aria-label={`${permission.libelle} — ${role.libelle}`}
                              disabled={enCours === cle}
                              onClick={() => basculer(role.uuid, role.permissions, permission.code, !accordee)}
                              className={`h-[19px] w-[34px] rounded-full transition-colors disabled:opacity-60 ${
                                accordee ? 'bg-accent' : 'bg-track'
                              }`}
                            >
                              <span
                                className={`block h-[15px] w-[15px] rounded-full bg-surface shadow transition-transform ${
                                  accordee ? 'translate-x-[17px]' : 'translate-x-[2px]'
                                }`}
                              />
                            </button>
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
