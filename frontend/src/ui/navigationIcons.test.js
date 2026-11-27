import { describe, expect, it } from 'vitest'
import { RectangleGroupIcon } from '@heroicons/vue/24/outline'
import { navigationIconFor } from './navigationIcons.js'

// Las entradas de cada barra, como las arma AppShellPayload.
const bars = {
  mantenimiento: ['dashboard', 'my-work', 'report-failure', 'equipment', 'fleet-telemetry', 'fleet-consumption', 'quick-readings', 'reading-control', 'maintenance', 'work-orders', 'work-requests', 'plans', 'services', 'employees', 'expirations', 'providers', 'masters-equipment', 'masters-expirations', 'imports', 'notifications', 'branches', 'integrations', 'users', 'chatbot-audit'],
  superadmin: ['dashboard', 'superadmin', 'whatsapp-digest-preview', 'telemetry-samples', 'notification-settings', 'chatbot-audit', 'audit-changes'],
  viajes: ['trips-operations-dashboard', 'trips-dashboard', 'trips-operations', 'trips-fuel-pending', 'trips-profitability', 'trips-commercial-closing', 'customer-accounts', 'trip-masters'],
  gerencial: ['managerial-cost-km', 'managerial-fleet-board'],
  combustible: ['fuel-module', 'fuel-pending'],
  neumaticos: ['tire-registry', 'tire-deposit', 'tire-masters'],
  portal: ['platform-home', 'platform-admin'],
}

describe('navigationIconFor', () => {
  it.each(Object.entries(bars))('en la barra de %s no se repite ningún ícono', (_, keys) => {
    const icons = keys.map((key) => navigationIconFor({ key }))
    expect(icons).not.toContain(RectangleGroupIcon) // toda entrada conocida tiene su ícono propio
    expect(new Set(icons).size).toBe(keys.length)
  })

  it('los módulos del portal usan su ícono de catálogo, y lo desconocido cae en uno genérico', () => {
    expect(navigationIconFor({ key: 'module-maintenance', icon: 'module-wrench' }))
      .toBe(navigationIconFor({ key: 'work-orders' }))
    expect(navigationIconFor({ key: 'algo-nuevo', icon: 'desconocido' })).toBe(RectangleGroupIcon)
  })
})
