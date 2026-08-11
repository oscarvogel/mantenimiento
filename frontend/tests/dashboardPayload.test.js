import { describe, expect, it } from 'vitest'
import { normalizeDashboardPayload } from '../src/adapters/dashboardPayload.js'
import { dashboardPayload } from './fixtures.js'

describe('normalizeDashboardPayload', () => {
  it('adapta el modelo de lectura autorizado a la vista', () => {
    const dashboard = normalizeDashboardPayload(dashboardPayload)

    expect(dashboard.available).toBe(true)
    expect(dashboard.user.name).toBe('Ana Pérez')
    expect(dashboard.metrics.equipmentTotal).toBe(24)
    expect(dashboard.metrics.maintenanceDueSoon).toBe(5)
    expect(dashboard.upcomingMaintenance[0].status).toBe('PROXIMO')
    expect(dashboard.upcomingMaintenance[0].tone).toBe('due')
    expect(dashboard.navigation[1].href).toBe('/mantenimiento/equipos')
  })

  it('rechaza URLs javascript y normaliza valores inseguros', () => {
    const dashboard = normalizeDashboardPayload({
      metrics: { equipmentTotal: -4, maintenanceDueSoon: '3.8', maintenanceOverdue: 'invalid' },
      navigation: [{ label: 'Ataque', href: 'javascript:alert(1)' }],
      upcomingMaintenance: [{ planId: 1, status: 'inventado' }],
    })

    expect(dashboard.metrics.equipmentTotal).toBe(0)
    expect(dashboard.metrics.maintenanceDueSoon).toBe(3)
    expect(dashboard.metrics.maintenanceOverdue).toBe(0)
    expect(dashboard.navigation[0].href).toBe('#')
    expect(dashboard.upcomingMaintenance[0].status).toBe('SIN_DATOS')
    expect(dashboard.upcomingMaintenance[0].tone).toBe('inactive')
  })

  it('produce un estado vacío seguro cuando falta el payload', () => {
    const dashboard = normalizeDashboardPayload(null)

    expect(dashboard.available).toBe(false)
    expect(dashboard.navigation).toEqual([])
    expect(dashboard.upcomingMaintenance).toEqual([])
    expect(dashboard.metrics.equipmentTotal).toBe(0)
    expect(dashboard.metrics.maintenanceDueSoon).toBe(0)
    expect(dashboard.metrics.maintenanceOverdue).toBe(0)
  })

  it('contempla el modo global y la ausencia de sucursales', () => {
    const globalDashboard = normalizeDashboardPayload({
      mode: 'global',
      user: { name: 'Super Admin', isSuperAdmin: true },
      company: { branches: [] },
    })
    const tenantDashboard = normalizeDashboardPayload({ mode: 'tenant', company: { name: 'Empresa', branches: [] } })

    expect(globalDashboard.company.name).toBe('Administración global')
    expect(globalDashboard.company.scopeLabel).toBe('Todas las empresas')
    expect(globalDashboard.user.roleLabel).toBe('Superadministrador')
    expect(tenantDashboard.company.scopeLabel).toBe('Sin sucursales asignadas')
  })

  it('prioriza Planes preventivos para los indicadores de mantenimiento', () => {
    const dashboard = normalizeDashboardPayload({
      navigation: [
        { key: 'plans', label: 'Planes preventivos', href: '/mantenimiento/planes' },
        { key: 'maintenance', label: 'Servicios', href: '/mantenimiento' },
      ],
    })

    expect(dashboard.links.maintenance).toBe('/mantenimiento/planes')
  })
})
