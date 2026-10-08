import { describe, expect, it } from 'vitest'
import { normalizeAppShellPayload, normalizeDashboardPayload } from '../src/adapters/dashboardPayload.js'
import { dashboardPayload } from './fixtures.js'

describe('normalizeDashboardPayload', () => {
  it('keeps the backend-provided portal return URL in the shared shell', () => {
    const shell = normalizeAppShellPayload({ homeUrl: '/inicio' })

    expect(shell.homeUrl).toBe('/inicio')
  })

  it('rejects an unsafe portal return URL', () => {
    const shell = normalizeAppShellPayload({ homeUrl: 'javascript:alert(1)' })

    expect(shell.homeUrl).toBe('#')
  })

  it('preserves the status of disabled platform modules for assistive labels', () => {
    const shell = normalizeAppShellPayload({
      navigation: [{
        key: 'module-trips',
        label: 'Viajes',
        icon: 'module-truck',
        href: null,
        disabled: true,
        status: 'Sin implementación aún',
      }],
    })

    expect(shell.navigation[0].disabled).toBe(true)
    expect(shell.navigation[0].status).toBe('Sin implementación aún')
  })

  it('normalizes the module quick nav without assigning a route to future modules', () => {
    const shell = normalizeAppShellPayload({
      moduleNavigation: [
        { key: 'platform-home', label: 'Inicio', href: '/inicio' },
        { key: 'module-maintenance', label: 'Mantenimiento', href: '/dashboard', active: true },
        { key: 'module-trips', label: 'Viajes', href: '/viajes', disabled: true, status: 'Sin implementación aún' },
      ],
    })

    expect(shell.moduleNavigation[0].href).toBe('/inicio')
    expect(shell.moduleNavigation[1].href).toBe('/dashboard')
    expect(shell.moduleNavigation[1].active).toBe(true)
    expect(shell.moduleNavigation[2].href).toBe(null)
    expect(shell.moduleNavigation[2].status).toBe('Sin implementación aún')
  })

  it('normalizes only known operational chart states and never accepts negative counts', () => {
    const dashboard = normalizeDashboardPayload({
      charts: {
        preventiveByState: [
          { status: 'VENCIDO', label: 'Vencidos', count: 3 },
          { status: 'FICTICIO', label: 'No válido', count: 999 },
        ],
        openOrdersByState: [{ status: 'EN_PROCESO', label: 'En proceso', count: -2 }],
      },
    })

    expect(dashboard.charts.preventiveByState).toEqual([
      { status: 'VENCIDO', label: 'Vencidos', count: 3 },
    ])
    expect(dashboard.charts.openOrdersByState).toEqual([
      { status: 'EN_PROCESO', label: 'En proceso', count: 0 },
    ])
  })

  it('adapta el modelo de lectura autorizado a la vista', () => {
    const dashboard = normalizeDashboardPayload(dashboardPayload)

    expect(dashboard.available).toBe(true)
    expect(dashboard.user.name).toBe('Ana Pérez')
    expect(dashboard.metrics.equipmentTotal).toBe(24)
    expect(dashboard.metrics.equipmentWithoutPlans).toBe(2)
    expect(dashboard.metrics.plansConfigured).toBe(18)
    expect(dashboard.metrics.maintenanceDueSoon).toBe(5)
    expect(dashboard.metrics.maintenanceMissingData).toBe(1)
    expect(dashboard.metrics.openOrders).toBe(3)
    expect(dashboard.upcomingMaintenance[0].status).toBe('VENCIDO')
    expect(dashboard.upcomingMaintenance[0].tone).toBe('overdue')
    expect(dashboard.upcomingMaintenance[1].status).toBe('PROXIMO')
    expect(dashboard.upcomingMaintenance[1].actionLabel).toBe('Ver plan')
    expect(dashboard.upcomingMaintenance[1].actionUrl).toContain('equipo_id=9')
    expect(dashboard.links.quickReadings).toBe('/mantenimiento/lecturas/rapidas')
    expect(dashboard.links.assignPlan).toBe('/mantenimiento/planes')
    expect(dashboard.links.orders).toBe('/mantenimiento')
    expect(dashboard.links.maintenanceMissingData).toBe('/mantenimiento/planes?estado=SIN_DATOS')
    expect(dashboard.links.maintenanceOverdue).toBe('/mantenimiento/planes?estado=VENCIDO')
    expect(dashboard.navigation[1].href).toBe('/mantenimiento/equipos')
  })

  it('rechaza URLs javascript y normaliza valores inseguros', () => {
    const dashboard = normalizeDashboardPayload({
      metrics: { equipmentTotal: -4, maintenanceDueSoon: '3.8', maintenanceOverdue: 'invalid' },
      navigation: [{ label: 'Ataque', href: 'javascript:alert(1)' }],
      links: { quickReadings: 'javascript:alert(1)', orders: 'javascript:alert(1)' },
      upcomingMaintenance: [{ planId: 1, status: 'inventado' }],
    })

    expect(dashboard.metrics.equipmentTotal).toBe(0)
    expect(dashboard.metrics.maintenanceDueSoon).toBe(3)
    expect(dashboard.metrics.maintenanceOverdue).toBe(0)
    expect(dashboard.navigation[0].href).toBe('#')
    expect(dashboard.links.quickReadings).toBe('#')
    expect(dashboard.links.orders).toBe('#')
    expect(dashboard.upcomingMaintenance[0].status).toBe('SIN_DATOS')
    expect(dashboard.upcomingMaintenance[0].tone).toBe('inactive')
  })

  it('produce un estado vacío seguro cuando falta el payload', () => {
    const dashboard = normalizeDashboardPayload(null)

    expect(dashboard.available).toBe(false)
    expect(dashboard.navigation).toEqual([])
    expect(dashboard.upcomingMaintenance).toEqual([])
    expect(dashboard.metrics.equipmentTotal).toBe(0)
    expect(dashboard.metrics.equipmentWithoutPlans).toBe(0)
    expect(dashboard.metrics.plansConfigured).toBe(0)
    expect(dashboard.metrics.maintenanceDueSoon).toBe(0)
    expect(dashboard.metrics.maintenanceOverdue).toBe(0)
    expect(dashboard.metrics.maintenanceMissingData).toBe(0)
    expect(dashboard.links.orders).toBe('#')
  })

  it('preserves missing preventive compliance data instead of converting it to zero', () => {
    const dashboard = normalizeDashboardPayload({ metrics: { preventiveCompliance: null } })

    expect(dashboard.metrics.preventiveCompliance).toBe(null)
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

  it('prioriza Planes preventivos para los indicadores de mantenimiento sin inventar acceso a órdenes', () => {
    const dashboard = normalizeDashboardPayload({
      navigation: [
        { key: 'plans', label: 'Planes preventivos', href: '/mantenimiento/planes' },
        { key: 'maintenance', label: 'Servicios', href: '/mantenimiento' },
      ],
    })

    expect(dashboard.links.maintenance).toBe('/mantenimiento/planes')
    expect(dashboard.links.orders).toBe('#')
  })

  it('oculta acciones y filtros cuando no existen permisos de destino', () => {
    const dashboard = normalizeDashboardPayload({
      metrics: { maintenanceOverdue: 2 },
      upcomingMaintenance: [{ planId: 2, equipmentId: 9, status: 'VENCIDO' }],
    })

    expect(dashboard.links.maintenanceOverdue).toBe('#')
    expect(dashboard.links.assignPlan).toBe('#')
    expect(dashboard.links.quickReadings).toBe('#')
    expect(dashboard.links.orders).toBe('#')
    expect(dashboard.upcomingMaintenance[0].actionUrl).toBe(null)
    expect(dashboard.upcomingMaintenance[0].actionLabel).toBe(null)
  })

  it('conserva el CTA contextual para lectura y gestión de vencidos', () => {
    const readOnly = normalizeDashboardPayload({
      upcomingMaintenance: [{ planId: 2, equipmentId: 9, status: 'VENCIDO', actionLabel: 'Ver vencido', actionUrl: '/mantenimiento/planes?equipo_id=9&estado=VENCIDO' }],
    })
    const manager = normalizeDashboardPayload({
      upcomingMaintenance: [{ planId: 2, equipmentId: 9, status: 'VENCIDO', actionLabel: 'Atender', actionUrl: '/mantenimiento/planes?equipo_id=9&estado=VENCIDO' }],
    })

    expect(readOnly.upcomingMaintenance[0].actionLabel).toBe('Ver vencido')
    expect(manager.upcomingMaintenance[0].actionLabel).toBe('Atender')
  })
})
